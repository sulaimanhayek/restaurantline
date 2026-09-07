<?php

declare(strict_types=1);

use App\Enums\ConversationOutcome;
use App\Jobs\ProcessElevenLabsWebhook;
use App\Models\Conversation;
use App\Models\Order;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WebhookPayloads;

/**
 * What the post-call webhook actually records.
 *
 * The field names are the whole point. Everything here would pass just as
 * happily against invented ones, so the payloads come from a single fixture
 * built against the platform's documentation rather than from each test's
 * imagination — see Tests\Support\WebhookPayloads.
 */
beforeEach(function (): void {
    $this->restaurant = restaurant(['elevenlabs_agent_id' => 'agent_abc123']);
    config(['restaurantline.elevenlabs.webhook_secret' => 'whsec_test_secret']);
});

/**
 * The controller's whole job. A webhook sender treats a slow response as a
 * failed delivery and sends the payload again, so anything expensive done
 * inline buys duplicates of itself.
 */
it('answers immediately and queues the work', function (): void {
    Queue::fake();

    postWebhook(WebhookPayloads::transcription())->assertOk();

    Queue::assertPushed(
        ProcessElevenLabsWebhook::class,
        fn (ProcessElevenLabsWebhook $job): bool => $job->type === 'post_call_transcription'
            && $job->data['conversation_id'] === 'conv-1',
    );
});

/**
 * A signed payload we cannot parse came from ElevenLabs, so it is a new event
 * shape rather than an attack. Worth a 200: a retry loop will not fix a shape
 * change, it will just deliver the same thing until it gives up.
 */
it('does not queue anything for an envelope it cannot read', function (): void {
    Queue::fake();

    postWebhook(['type' => 'post_call_transcription'])
        ->assertOk()
        ->assertJsonPath('handled', false);

    Queue::assertNothingPushed();
});

describe('post_call_transcription', function (): void {
    it('records the call', function (): void {
        postWebhook(WebhookPayloads::transcription())->assertOk();

        $conversation = Conversation::query()->where('elevenlabs_conversation_id', 'conv-1')->sole();

        expect($conversation->elevenlabs_agent_id)->toBe('agent_abc123')
            ->and($conversation->duration_seconds)->toBe(94)
            ->and($conversation->cost_credits)->toBe(320)
            ->and($conversation->transcript)->toHaveCount(2)
            ->and($conversation->analysis['transcript_summary'])
            ->toBe('Caller ordered two chicken burgers for collection.');
    });

    /**
     * Not a top-level field. It arrives as the `system__caller_id` dynamic
     * variable, nested two levels down, and a reader that looked for
     * `caller_number` would find nothing and record nothing — silently, since
     * the column is nullable for the withheld-number case.
     */
    it('finds the caller number where it actually lives', function (): void {
        postWebhook(WebhookPayloads::transcription())->assertOk();

        expect(Conversation::query()->sole()->caller_number)->toBe('+447700900123');
    });

    it('leaves the number null when the caller withheld it', function (): void {
        postWebhook(WebhookPayloads::transcription(overrides: [
            'conversation_initiation_client_data' => ['dynamic_variables' => []],
        ]))->assertOk();

        expect(Conversation::query()->sole()->caller_number)->toBeNull();
    });

    it('derives the end of the call from the start and the duration', function (): void {
        postWebhook(WebhookPayloads::transcription())->assertOk();

        $conversation = Conversation::query()->sole();

        expect($conversation->started_at?->getTimestamp())->toBe(1_788_000_000)
            ->and($conversation->ended_at?->getTimestamp())->toBe(1_788_000_094);
    });

    /**
     * The row usually exists before the webhook arrives — the agent tools
     * create it mid-call the moment an order happens. This has to find it
     * rather than insert a second one, or every order loses its transcript.
     */
    it('updates the row the agent tools already created', function (): void {
        $existing = Conversation::factory()->for($this->restaurant)->create([
            'elevenlabs_conversation_id' => 'conv-1',
            'outcome' => ConversationOutcome::Pending,
            'transcript' => null,
        ]);

        postWebhook(WebhookPayloads::transcription())->assertOk();

        expect(Conversation::query()->count())->toBe(1)
            ->and($existing->fresh()?->transcript)->toHaveCount(2);
    });

    /**
     * A call that asked about opening times and hung up never touches an agent
     * tool that writes a row, so this is where it gets one. Those are the calls
     * worth reading.
     */
    it('creates a row for a call that never reached a tool', function (): void {
        postWebhook(WebhookPayloads::transcription('conv-never-ordered'))->assertOk();

        expect(Conversation::query()->where('elevenlabs_conversation_id', 'conv-never-ordered')->exists())
            ->toBeTrue();
    });

    /**
     * Redelivery is normal — a timeout on our side, a retry on theirs — and
     * must converge rather than duplicate.
     */
    it('is idempotent across a redelivery', function (): void {
        postWebhook(WebhookPayloads::transcription())->assertOk();
        postWebhook(WebhookPayloads::transcription())->assertOk();

        expect(Conversation::query()->count())->toBe(1);
    });

    it('keeps the whole payload verbatim', function (): void {
        postWebhook(WebhookPayloads::transcription())->assertOk();

        expect(Conversation::query()->sole()->raw_payload)
            ->toHaveKey('has_audio')
            ->toHaveKey('agent_name');
    });

    /**
     * Fields appear in this payload as the platform evolves, and a missing one
     * should cost a null column rather than a failed job and a retry storm.
     */
    it('survives a payload with everything optional missing', function (): void {
        postWebhook([
            'type' => 'post_call_transcription',
            'event_timestamp' => now()->getTimestamp(),
            'data' => ['conversation_id' => 'conv-sparse'],
        ])->assertOk();

        $conversation = Conversation::query()->where('elevenlabs_conversation_id', 'conv-sparse')->sole();

        expect($conversation->duration_seconds)->toBeNull()
            ->and($conversation->transcript)->toBeNull();
    });
});

describe('outcomes', function (): void {
    it('calls a transcript with no order an enquiry', function (): void {
        postWebhook(WebhookPayloads::transcription())->assertOk();

        expect(Conversation::query()->sole()->outcome)->toBe(ConversationOutcome::EnquiryOnly);
    });

    it('calls an unsuccessful call with no order abandoned, and flags it', function (): void {
        postWebhook(WebhookPayloads::transcription(overrides: [
            'analysis' => ['call_successful' => 'failure', 'evaluation_criteria_results' => []],
        ]))->assertOk();

        $conversation = Conversation::query()->sole();

        expect($conversation->outcome)->toBe(ConversationOutcome::OrderAbandoned)
            ->and($conversation->needs_review)->toBeTrue();
    });

    /**
     * ElevenLabs' `call_successful` is their judgement of how the conversation
     * went, which is a different question from whether an order exists. The
     * order is the fact; their analysis is an opinion, and the fact wins.
     */
    it('does not let their analysis overrule an order that exists', function (): void {
        $conversation = Conversation::factory()->for($this->restaurant)->create([
            'elevenlabs_conversation_id' => 'conv-1',
            'outcome' => ConversationOutcome::Pending,
        ]);

        Order::factory()->for($this->restaurant)->create([
            'conversation_id' => $conversation->id,
            'elevenlabs_conversation_id' => 'conv-1',
        ]);

        postWebhook(WebhookPayloads::transcription(overrides: [
            'analysis' => ['call_successful' => 'failure', 'evaluation_criteria_results' => []],
        ]))->assertOk();

        expect($conversation->fresh()?->outcome)->toBe(ConversationOutcome::OrderPlaced);
    });

    /**
     * An outcome the agent tools already set is a first-hand account of what
     * happened. The webhook arrives later with less information.
     */
    it('leaves an outcome the agent tools already decided', function (): void {
        Conversation::factory()->for($this->restaurant)->create([
            'elevenlabs_conversation_id' => 'conv-1',
            'outcome' => ConversationOutcome::Escalated,
        ]);

        postWebhook(WebhookPayloads::transcription())->assertOk();

        expect(Conversation::query()->sole()->outcome)->toBe(ConversationOutcome::Escalated);
    });

    /**
     * Evaluation criteria are configured per agent, so this does nothing on a
     * fresh install and becomes the main review signal once a forker sets them
     * up. A failed criterion is worth a look even when an order came out fine.
     */
    it('flags a call that failed an evaluation criterion', function (): void {
        postWebhook(WebhookPayloads::transcription(overrides: [
            'analysis' => [
                'call_successful' => 'success',
                'evaluation_criteria_results' => [
                    'read_order_back' => ['result' => 'failure', 'rationale' => 'Never confirmed the items.'],
                ],
            ],
        ]))->assertOk();

        $conversation = Conversation::query()->sole();

        expect($conversation->needs_review)->toBeTrue()
            ->and($conversation->review_reason)->toContain('read_order_back');
    });

    it('leaves a clean call unflagged', function (): void {
        postWebhook(WebhookPayloads::transcription())->assertOk();

        expect(Conversation::query()->sole()->needs_review)->toBeFalse();
    });
});

describe('post_call_audio', function (): void {
    beforeEach(function (): void {
        Storage::fake('local');
    });

    /**
     * Stored rather than linked, because ElevenLabs' links expire and the
     * recordings that matter are the ones somebody goes looking for months
     * later, when the transcript is already disputed.
     */
    it('writes the recording to our own disk', function (): void {
        postWebhook(WebhookPayloads::audio('conv-1', 'pretend-mp3-bytes'))->assertOk();

        $conversation = Conversation::query()->sole();

        expect($conversation->audio_path)->toBe("conversations/{$this->restaurant->id}/conv-1.mp3");

        Storage::disk('local')->assertExists($conversation->audio_path);
        expect(Storage::disk('local')->get($conversation->audio_path))->toBe('pretend-mp3-bytes');
    });

    /**
     * The two events are not alternatives and arrive in no guaranteed order.
     * Audio first must not lose the transcript that follows.
     */
    it('converges on one row whichever event arrives first', function (): void {
        postWebhook(WebhookPayloads::audio('conv-1'))->assertOk();
        postWebhook(WebhookPayloads::transcription('conv-1'))->assertOk();

        $conversation = Conversation::query()->sole();

        expect($conversation->audio_path)->not->toBeNull()
            ->and($conversation->transcript)->toHaveCount(2);
    });

    it('ignores audio it cannot decode rather than failing the job', function (): void {
        postWebhook([
            'type' => 'post_call_audio',
            'event_timestamp' => now()->getTimestamp(),
            'data' => ['conversation_id' => 'conv-1', 'full_audio' => ''],
        ])->assertOk();

        expect(Conversation::query()->where('elevenlabs_conversation_id', 'conv-1')->first()?->audio_path)
            ->toBeNull();
    });
});

describe('call_initiation_failure', function (): void {
    /**
     * A call that never connected produced no conversation, but a run of these
     * means the number is misconfigured or the agent is unreachable — and a
     * restaurant whose line has silently stopped answering will not learn it
     * from anywhere else.
     */
    it('records a failed call and flags it for a human', function (): void {
        postWebhook(WebhookPayloads::initiationFailure('conv-failed', 'no-answer'))->assertOk();

        $conversation = Conversation::query()->where('elevenlabs_conversation_id', 'conv-failed')->sole();

        expect($conversation->outcome)->toBe(ConversationOutcome::CallFailed)
            ->and($conversation->needs_review)->toBeTrue()
            ->and($conversation->review_reason)->toContain('no-answer');
    });

    it('keeps the provider detail for whoever has to debug the phone number', function (): void {
        postWebhook(WebhookPayloads::initiationFailure())->assertOk();

        expect(Conversation::query()->sole()->raw_payload['metadata']['body']['CallSid'])->toBe('CA123');
    });
});

/**
 * New event types appear. A fork that has not been updated should ignore one
 * rather than fail the job and retry it until the sender gives up.
 */
it('ignores an event type it does not know', function (): void {
    postWebhook([
        'type' => 'post_call_something_new',
        'event_timestamp' => now()->getTimestamp(),
        'data' => ['conversation_id' => 'conv-1'],
    ])->assertOk();

    expect(Conversation::query()->count())->toBe(0);
});
