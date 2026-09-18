<?php

declare(strict_types=1);

use App\Enums\ConversationOutcome;
use App\Models\Conversation;
use App\Services\Demo\WavFile;
use App\Services\ElevenLabs\ElevenLabsClient;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @param  list<array{0: string, 1: string}>  $turns
 * @param  array<string, mixed>  $attributes
 */
function conversationWith(array $turns, string $id = 'conv_test', array $attributes = []): Conversation
{
    return Conversation::factory()->for(restaurant())->create(array_replace([
        'elevenlabs_conversation_id' => $id,
        'outcome' => ConversationOutcome::OrderPlaced,
        'transcript' => array_map(
            fn (array $turn): array => ['role' => $turn[0], 'message' => $turn[1], 'time_in_call_secs' => 0],
            $turns,
        ),
    ], $attributes));
}

it('renders a stored conversation to a playable file', function (): void {
    conversationWith([
        ['agent', 'Ember Grill, is it delivery or collection?'],
        ['user', 'Delivery please.'],
    ]);

    $this->artisan('kitchenline:demo:render')
        ->expectsOutputToContain('conv_test')
        ->assertSuccessful();

    $path = 'demos/conv_test.wav';

    Storage::disk('local')->assertExists($path);

    // Read back through the parser rather than trusting the byte count: a file
    // of the right size with an unreadable header is exactly the failure this
    // whole command exists to avoid.
    $wav = WavFile::parse((string) Storage::disk('local')->get($path));

    expect($wav->sampleRate)->toBe(24_000)
        ->and($wav->durationSeconds())->toBeGreaterThan(0.0);
});

it('warns in capitals that fake-mode audio is silent', function (): void {
    conversationWith([['agent', 'Ember Grill.']]);

    // The suite runs on the fake driver, which is the point: a developer's
    // first run of this command is on the fake driver too.
    $this->artisan('kitchenline:demo:render')
        ->expectsOutputToContain('THIS FILE IS SILENT')
        ->assertSuccessful();
});

it('renders the conversation you name', function (): void {
    conversationWith([['agent', 'The old one.']], 'conv_old');
    conversationWith([['agent', 'The new one.']], 'conv_new');

    $this->artisan('kitchenline:demo:render', ['conversation' => 'conv_old'])->assertSuccessful();

    Storage::disk('local')->assertExists('demos/conv_old.wav');
    Storage::disk('local')->assertMissing('demos/conv_new.wav');
});

it('defaults to the most recent conversation that has a transcript', function (): void {
    conversationWith([['agent', 'Yesterday.']], 'conv_old', ['started_at' => now()->subDay()]);
    conversationWith([['agent', 'An hour ago.']], 'conv_recent', ['started_at' => now()->subHour()]);
    // A call that never finished: a row exists, the webhook never arrived.
    conversationWith([], 'conv_live', ['started_at' => now(), 'transcript' => null]);

    $this->artisan('kitchenline:demo:render')->assertSuccessful();

    Storage::disk('local')->assertExists('demos/conv_recent.wav');
});

it('fails with an explanation when the conversation has no transcript', function (): void {
    conversationWith([], 'conv_live', ['transcript' => null]);

    $this->artisan('kitchenline:demo:render', ['conversation' => 'conv_live'])
        ->expectsOutputToContain('has not arrived yet')
        ->assertFailed();
});

it('fails with an explanation when there is nothing to render at all', function (): void {
    restaurant();

    $this->artisan('kitchenline:demo:render')
        ->expectsOutputToContain('migrate --seed')
        ->assertFailed();
});

it('writes where --out says', function (): void {
    conversationWith([['agent', 'Ember Grill.']]);

    $target = sys_get_temp_dir().'/restaurantline-demo-test/pitch.wav';

    $this->artisan('kitchenline:demo:render', ['--out' => $target])->assertSuccessful();

    expect(file_exists($target))->toBeTrue()
        ->and(WavFile::parse((string) file_get_contents($target))->sampleRate)->toBe(24_000);

    unlink($target);
    rmdir(dirname($target));
});

it('needs two voice ids before it will call a real account', function (): void {
    conversationWith([['agent', 'Ember Grill.']]);

    config()->set('restaurantline.demo.caller_voice_id', null);
    config()->set('restaurantline.demo.agent_voice_id', null);

    // Nothing here talks to ElevenLabs: the check happens before the first
    // request, so a missing id costs a line of output rather than a 404 that
    // reads like a bug in this application.
    $this->mock(ElevenLabsClient::class)
        ->shouldReceive('name')->andReturn('api')->getMock()
        ->shouldNotReceive('textToSpeech');

    $this->artisan('kitchenline:demo:render')
        ->expectsOutputToContain('DEMO_CALLER_VOICE_ID')
        ->assertFailed();
});

it('scopes to a restaurant when asked, and says so when the slug is wrong', function (): void {
    $this->artisan('kitchenline:demo:render', ['--restaurant' => 'no-such-place'])
        ->expectsOutputToContain('no-such-place')
        ->assertFailed();
});
