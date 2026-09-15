<?php

declare(strict_types=1);

use App\Enums\ConversationOutcome;
use App\Models\Conversation;

/**
 * The escape hatch. Its one hard requirement is that it cannot fail — a caller
 * who has asked for a person and been told "sorry, something went wrong" is in
 * precisely the loop this endpoint exists to break.
 */
beforeEach(function (): void {
    $this->restaurant = restaurant();
});

it('hands back the transfer number and something to say', function (): void {
    $this->restaurant->update(['transfer_phone_number' => '+442071234567']);

    agentPost('escalate', ['conversation_id' => 'call-1', 'reason' => 'caller has a nut allergy question'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('transfer_available', true)
        ->assertJsonPath('transfer_phone_number', '+442071234567')
        ->assertJsonPath('say', 'Of course — let me put you through to someone who can help. Bear with me one moment.');
});

/**
 * An installation with no transfer number still has to answer. Returning an
 * error here would mean the agent's only route to a human is the one thing in
 * the system with no fallback.
 */
it('still succeeds with no transfer number configured', function (): void {
    $this->restaurant->update(['transfer_phone_number' => null]);

    agentPost('escalate', ['conversation_id' => 'call-1', 'reason' => 'caller is upset'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('transfer_available', false)
        ->assertJsonMissingPath('transfer_phone_number');
});

it('flags the conversation for review with the reason given', function (): void {
    agentPost('escalate', ['conversation_id' => 'call-1', 'reason' => 'could not understand the caller'])
        ->assertJsonPath('conversation_flagged', true);

    $conversation = Conversation::query()->where('elevenlabs_conversation_id', 'call-1')->sole();

    expect($conversation->needs_review)->toBeTrue()
        ->and($conversation->review_reason)->toBe('could not understand the caller')
        ->and($conversation->outcome)->toBe(ConversationOutcome::Escalated)
        ->and($conversation->restaurant_id)->toBe($this->restaurant->id);
});

it('escalates an existing conversation without starting a second row', function (): void {
    $existing = Conversation::factory()->for($this->restaurant)->create([
        'elevenlabs_conversation_id' => 'call-1',
    ]);

    agentPost('escalate', ['conversation_id' => 'call-1', 'reason' => 'asked for the manager']);

    expect(Conversation::query()->where('elevenlabs_conversation_id', 'call-1')->count())->toBe(1)
        ->and($existing->refresh()->outcome)->toBe(ConversationOutcome::Escalated);
});

it('requires a reason, because an unexplained escalation teaches nothing', function (): void {
    agentPost('escalate', ['conversation_id' => 'call-1'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_request');
});

/**
 * The reason is free text read back by nobody and stored forever, which makes
 * it exactly the field a caller reciting their card number ends up in.
 */
it('refuses a reason containing a card number', function (): void {
    agentPost('escalate', [
        'conversation_id' => 'call-1',
        'reason' => 'caller wants to pay with 4111 1111 1111 1111',
    ])->assertStatus(422);

    expect(Conversation::query()->count())->toBe(0);
});

/**
 * The conversation id comes back out, both with a transfer number and without.
 *
 * The model has no use for it — it never sees the dynamic variable this was
 * filled from — but a live eval does. This is the one endpoint a call can reach
 * without leaving an order behind, so it is the only way to identify a call
 * that ended with "let me put you through to someone".
 */
it('echoes the conversation id back so a transcript can be matched to a call', function (): void {
    $this->restaurant->update(['transfer_phone_number' => '+442071234567']);

    agentPost('escalate', ['conversation_id' => 'call-1', 'reason' => 'asked for the manager'])
        ->assertOk()
        ->assertJsonPath('conversation', 'call-1');

    $this->restaurant->update(['transfer_phone_number' => null]);

    agentPost('escalate', ['conversation_id' => 'call-2', 'reason' => 'asked for the manager'])
        ->assertOk()
        ->assertJsonPath('transfer_available', false)
        ->assertJsonPath('conversation', 'call-2');
});
