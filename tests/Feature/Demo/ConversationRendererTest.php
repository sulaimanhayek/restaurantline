<?php

declare(strict_types=1);

use App\Services\Demo\ConversationRenderer;
use App\Services\Demo\DemoRenderException;
use App\Services\Demo\WavFile;
use App\Services\ElevenLabs\ElevenLabsClient;

/**
 * A client that hands back audible, identifiable audio.
 *
 * The shipped fake returns silence, which is right for it and useless here:
 * these tests are about whether the right voice said the right line in the
 * right order, and every turn being zeroes makes all of those unfalsifiable.
 * Each turn is filled with a distinct byte instead, so the stitched result can
 * be read back like a sentence.
 *
 * @param  array<string, string>  $byteFor  Voice id to the byte its speech is made of.
 */
function speakingClient(array $byteFor, int $sampleRate = 24_000, float $seconds = 0.5): ElevenLabsClient
{
    $client = Mockery::mock(ElevenLabsClient::class);

    $client->shouldReceive('name')->andReturn('api');
    $client->shouldReceive('textToSpeech')->andReturnUsing(
        function (string $voiceId, string $text, string $format) use ($byteFor, $sampleRate, $seconds): string {
            $bytes = (int) ($sampleRate * 2 * $seconds);

            return WavFile::silent($sampleRate, 0.0)
                ->withSamples(str_repeat($byteFor[$voiceId] ?? "\x01", $bytes))
                ->toBytes();
        },
    );

    /** @var ElevenLabsClient $client */
    return $client;
}

/**
 * @param  list<array{0: string, 1: string}>  $turns
 * @return list<array{role: string, message: string, at: int|null}>
 */
function turns(array $turns): array
{
    return array_map(
        fn (array $turn): array => ['role' => $turn[0], 'message' => $turn[1], 'at' => null],
        $turns,
    );
}

it('gives the caller and the agent different voices', function (): void {
    $renderer = new ConversationRenderer(speakingClient(['caller-voice' => "\x11", 'agent-voice' => "\x22"]));

    $demo = $renderer->render(
        turns([['agent', 'Ember Grill.'], ['user', 'Collection please.']]),
        'caller-voice',
        'agent-voice',
        gapSeconds: 0.0,
    );

    $samples = WavFile::parse($demo->wav)->samples;

    // Agent first, caller second — and `user` counted as the caller, which is
    // what ElevenLabs calls the person holding the phone.
    expect($samples[0])->toBe("\x22")
        ->and($samples[strlen($samples) - 1])->toBe("\x11")
        ->and($demo->turns)->toBe(2);
});

it('puts a pause between turns but not around them', function (): void {
    $renderer = new ConversationRenderer(speakingClient([], 24_000, 0.5));

    $demo = $renderer->render(turns([['agent', 'One.'], ['user', 'Two.'], ['agent', 'Three.']]), 'a', 'b', 0.25);

    // Three half-second turns and two quarter-second gaps, and nothing else:
    // a recording that opens or closes on silence sounds like a dropped call.
    expect(round($demo->seconds, 3))->toBe(2.0)
        ->and(WavFile::parse($demo->wav)->samples[0])->not->toBe("\0");
});

it('skips turns with nothing in them', function (): void {
    $renderer = new ConversationRenderer(speakingClient([]));

    // A tool call with no spoken text, which is what an escalation or a silent
    // hangup leaves in the transcript.
    $demo = $renderer->render(turns([['agent', 'Hello.'], ['user', '   '], ['agent', 'Still there?']]), 'a', 'b');

    expect($demo->turns)->toBe(2);
});

it('refuses a conversation with nothing spoken in it', function (): void {
    $renderer = new ConversationRenderer(speakingClient([]));

    expect(fn () => $renderer->render(turns([['user', '']]), 'a', 'b'))
        ->toThrow(DemoRenderException::class, 'anything in them');
});

it('will not join two different formats', function (): void {
    $client = Mockery::mock(ElevenLabsClient::class);
    $client->shouldReceive('name')->andReturn('api');
    $rates = [24_000, 44_100];
    // By reference: an arrow function would capture a fresh copy of `$rates`
    // on every call and hand back 24kHz twice, which is the case this test is
    // trying not to be.
    $client->shouldReceive('textToSpeech')->andReturnUsing(
        function () use (&$rates): string {
            return WavFile::silent((int) array_shift($rates), 0.2)->toBytes();
        },
    );

    /** @var ElevenLabsClient $client */
    $renderer = new ConversationRenderer($client);

    // Concatenating these would play the second turn at half speed rather than
    // fail, which is the kind of bug someone finds during the demo.
    expect(fn () => $renderer->render(turns([['agent', 'One.'], ['user', 'Two.']]), 'a', 'b'))
        ->toThrow(DemoRenderException::class, '44100 Hz');
});

it('reports the audio as silent only when the fake produced it', function (): void {
    $real = (new ConversationRenderer(speakingClient([])))->render(turns([['agent', 'Hi.']]), 'a', 'b');

    expect($real->silent)->toBeFalse();

    $fake = app(ConversationRenderer::class)->render(turns([['agent', 'Hi.']]), 'a', 'b');

    // The suite runs on the fake driver, so this is the branch that fires for
    // anyone trying the command before they have an account.
    expect($fake->silent)->toBeTrue();
});
