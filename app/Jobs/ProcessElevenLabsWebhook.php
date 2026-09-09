<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ConversationOutcome;
use App\Models\Conversation;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a post-call webhook into a Conversation row.
 *
 * Three event types arrive here, and they are not alternatives — a single call
 * produces a `post_call_transcription` and, if audio is enabled, a
 * `post_call_audio` afterwards. They may arrive in either order and either may
 * be redelivered, so every branch is written to converge on the same row rather
 * than to assume it is the first to touch it.
 *
 * Field names below are the platform's, verified against the documentation on
 * 2026-09-07. Everything is read defensively: this payload gains fields as the
 * platform evolves, and a missing one should cost a null column rather than a
 * failed job and a retry storm. The whole thing is kept verbatim in
 * `raw_payload` regardless, so nothing that arrives is ever actually lost.
 *
 * @see docs/DECISIONS.md #0006, #0024
 */
final class ProcessElevenLabsWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $type,
        public readonly array $data,
        public readonly int $eventTimestamp = 0,
    ) {}

    public function handle(): void
    {
        $conversationId = $this->data['conversation_id'] ?? null;

        if (! is_string($conversationId) || $conversationId === '') {
            Log::warning('A webhook arrived with no conversation id.', ['type' => $this->type]);

            return;
        }

        match ($this->type) {
            'post_call_transcription' => $this->transcription($conversationId),
            'post_call_audio' => $this->audio($conversationId),
            'call_initiation_failure' => $this->initiationFailure($conversationId),
            // Not an error. New event types appear, and a fork that has not
            // been updated should ignore one rather than fail the job.
            default => Log::info('Ignoring an unhandled webhook type.', ['type' => $this->type]),
        };
    }

    /**
     * The main event: transcript, timings, cost and their own analysis.
     *
     * The conversation row usually exists already — the agent tools create it
     * mid-call, the moment an order or an escalation happens — so this updates
     * rather than inserts. A call that asked about opening times and hung up
     * creates it here instead, and that is the point of the row existing at
     * all: a call with no order is still a call worth reading.
     */
    private function transcription(string $conversationId): void
    {
        $metadata = $this->arrayAt('metadata');
        $analysis = $this->arrayAt('analysis');
        $transcript = is_array($this->data['transcript'] ?? null) ? $this->data['transcript'] : null;

        $startedAt = $this->timestampAt($metadata, 'start_time_unix_secs');
        $duration = $this->intAt($metadata, 'call_duration_secs');

        $conversation = $this->conversation($conversationId);

        $conversation->fill(array_filter([
            'elevenlabs_agent_id' => $this->stringAt($this->data, 'agent_id'),
            'caller_number' => $this->callerNumber(),
            'started_at' => $startedAt,
            'ended_at' => $startedAt !== null && $duration !== null
                ? $startedAt->addSeconds($duration)
                : null,
            'duration_seconds' => $duration,
            'transcript' => $transcript,
            'analysis' => $analysis,
            'cost_credits' => $this->intAt($metadata, 'cost'),
        ], static fn (mixed $value): bool => $value !== null));

        // Overwritten unconditionally, unlike the fields above: a redelivery
        // carries the same payload, and there is nothing here worth preserving
        // from a previous attempt.
        $conversation->raw_payload = $this->data;

        $outcome = $this->outcome($conversation, $analysis);

        if ($outcome !== null) {
            $conversation->outcome = $outcome;
        }

        $conversation->save();

        $this->flagIfWorthReading($conversation, $analysis);
    }

    /**
     * The recording, base64-encoded MP3 in `full_audio`.
     *
     * Stored on our own disk rather than kept as a link, because ElevenLabs'
     * links expire and a transcript without audio is far harder to review — the
     * disagreements that reach a recording are exactly the ones where the
     * transcript is already disputed.
     */
    private function audio(string $conversationId): void
    {
        $encoded = $this->data['full_audio'] ?? null;

        if (! is_string($encoded) || $encoded === '') {
            Log::warning('A post-call audio webhook carried no audio.', ['conversation' => $conversationId]);

            return;
        }

        $audio = base64_decode($encoded, true);

        if ($audio === false) {
            Log::warning('A post-call audio webhook carried unreadable audio.', ['conversation' => $conversationId]);

            return;
        }

        $conversation = $this->conversation($conversationId);

        $path = sprintf('conversations/%d/%s.mp3', $conversation->restaurant_id, $conversationId);

        Storage::disk($this->disk())->put($path, $audio);

        $conversation->audio_path = $path;
        $conversation->elevenlabs_agent_id ??= $this->stringAt($this->data, 'agent_id');
        $conversation->save();
    }

    /**
     * The call never got as far as a conversation.
     *
     * Recorded rather than dropped. A run of these means the phone number is
     * misconfigured or the agent is unreachable, and a restaurant whose line
     * silently stops answering will not find out from anywhere else.
     */
    private function initiationFailure(string $conversationId): void
    {
        $reason = $this->stringAt($this->data, 'failure_reason') ?? 'unknown';

        $conversation = $this->conversation($conversationId);

        $conversation->fill([
            'elevenlabs_agent_id' => $this->stringAt($this->data, 'agent_id') ?? $conversation->elevenlabs_agent_id,
            'outcome' => ConversationOutcome::CallFailed,
            'raw_payload' => $this->data,
        ]);

        $conversation->save();
        $conversation->flagForReview('Call never connected: '.$reason);
    }

    /**
     * What the conversation amounted to.
     *
     * Deliberately never overwrites an outcome the agent tools already set. A
     * call that reached `/orders` knows it produced an order; the webhook's own
     * `call_successful` is ElevenLabs' judgement of how the *conversation*
     * went, which is a different question and a less reliable answer.
     *
     * @param  array<string, mixed>  $analysis
     */
    private function outcome(Conversation $conversation, array $analysis): ?ConversationOutcome
    {
        if ($conversation->outcome !== ConversationOutcome::Pending) {
            return null;
        }

        if ($conversation->producedAnOrder()) {
            return ConversationOutcome::OrderPlaced;
        }

        // A call that reached the agent, produced no order, and that ElevenLabs
        // considered unsuccessful is the one shape worth naming. Everything
        // else is an enquiry until a human says otherwise — guessing
        // "abandoned" from a short transcript would flood the review queue with
        // people asking what time we close.
        return ($analysis['call_successful'] ?? null) === 'failure'
            ? ConversationOutcome::OrderAbandoned
            : ConversationOutcome::EnquiryOnly;
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function flagIfWorthReading(Conversation $conversation, array $analysis): void
    {
        if ($conversation->needs_review) {
            return;
        }

        if ($conversation->outcome->warrantsReview()) {
            $conversation->flagForReview($conversation->outcome->label());

            return;
        }

        // Their evaluation criteria are configured per agent, so this is empty
        // on a fresh install and meaningful once a forker sets criteria up. A
        // failure on any one of them is worth a human look even when the call
        // otherwise went fine and an order came out of it.
        $criteria = $analysis['evaluation_criteria_results'] ?? null;

        if (! is_array($criteria)) {
            return;
        }

        foreach ($criteria as $name => $result) {
            if (is_array($result) && ($result['result'] ?? null) === 'failure') {
                $conversation->flagForReview('Failed evaluation criterion: '.(is_string($name) ? $name : 'unnamed'));

                return;
            }
        }
    }

    private function conversation(string $conversationId): Conversation
    {
        $restaurant = Restaurant::current();

        return Conversation::query()->firstOrCreate(
            ['elevenlabs_conversation_id' => $conversationId],
            [
                'restaurant_id' => $restaurant->id,
                'elevenlabs_agent_id' => $restaurant->elevenlabs_agent_id,
            ],
        );
    }

    /**
     * The caller's number, which is not a top-level field.
     *
     * Phone calls carry it as the `system__caller_id` dynamic variable, so it
     * arrives nested inside the conversation's initiation data. Withheld
     * numbers exist and arrive as nothing at all.
     */
    private function callerNumber(): ?string
    {
        $initiation = $this->arrayAt('conversation_initiation_client_data');
        $variables = $initiation['dynamic_variables'] ?? null;

        if (! is_array($variables)) {
            return null;
        }

        $number = $variables['system__caller_id'] ?? null;

        return is_string($number) && $number !== '' ? $number : null;
    }

    private function disk(): string
    {
        $disk = config('restaurantline.elevenlabs.audio_disk', 'local');

        return is_string($disk) ? $disk : 'local';
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayAt(string $key): array
    {
        $value = $this->data[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function stringAt(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function intAt(array $source, string $key): ?int
    {
        $value = $source[$key] ?? null;

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function timestampAt(array $source, string $key): ?CarbonImmutable
    {
        $value = $this->intAt($source, $key);

        return $value !== null && $value > 0 ? CarbonImmutable::createFromTimestamp($value, 'UTC') : null;
    }
}
