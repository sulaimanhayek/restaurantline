<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Services\ElevenLabs\ElevenLabsClient;

/**
 * A finished transcript, read out loud by two voices.
 *
 * The thing a client reacts to is hearing it. A transcript in a terminal shows
 * that the agent took the order; a recording shows what the restaurant's
 * customers will actually experience, and the two land very differently across
 * a table.
 *
 * This is deliberately downstream of everything. It renders conversations that
 * have already happened — a live eval, or a real call the post-call webhook
 * stored — and it has no part in taking one. Nothing here runs while a caller
 * is on the line, nothing streams, and nothing decides whose turn it is. Those
 * are ElevenLabs' job and this project does not do them; see docs/DECISIONS.md
 * for why a repository that scopes out audio nonetheless ships this.
 *
 * Two voices, because one voice reading both halves is not a demo of a
 * conversation, it is an audiobook.
 */
final class ConversationRenderer
{
    /**
     * Whose lines get the caller's voice.
     *
     * ElevenLabs says `user`; a hand-written transcript might reasonably say
     * `caller` or `customer`. Anything else — `agent`, and any future role such
     * as a tool result that leaks into the transcript — is the restaurant.
     */
    private const CALLER_ROLES = ['user', 'caller', 'customer'];

    public function __construct(private readonly ElevenLabsClient $client) {}

    /**
     * @param  list<array{role: string, message: string, at?: int|null}>  $turns
     * @param  (callable(int, int, string, string): void)|null  $onTurn  Progress: index, total, role, message.
     */
    public function render(
        array $turns,
        string $callerVoiceId,
        string $agentVoiceId,
        float $gapSeconds = 0.55,
        ?callable $onTurn = null,
    ): RenderedDemo {
        $spoken = array_values(array_filter($turns, fn (array $turn): bool => trim($turn['message']) !== ''));

        if ($spoken === []) {
            throw new DemoRenderException(
                'That conversation has no turns with anything in them, so there is nothing to say. '
                .'A conversation that failed before the agent spoke will look like this.',
            );
        }

        $first = null;
        $samples = '';
        $total = count($spoken);

        foreach ($spoken as $index => $turn) {
            $isCaller = in_array(strtolower($turn['role']), self::CALLER_ROLES, true);
            $message = trim($turn['message']);

            if ($onTurn !== null) {
                $onTurn($index + 1, $total, $turn['role'], $message);
            }

            $audio = WavFile::parse($this->client->textToSpeech(
                $isCaller ? $callerVoiceId : $agentVoiceId,
                $message,
                $this->outputFormat(),
            ));

            if ($first === null) {
                $first = $audio;
            } elseif (! $audio->sameFormatAs($first)) {
                throw new DemoRenderException(sprintf(
                    'Turn %d came back as %s but the first turn was %s. Two different formats cannot be '
                    .'joined by concatenating their samples; this usually means the voices are configured '
                    .'with different output settings.',
                    $index + 1,
                    $audio->describeFormat(),
                    $first->describeFormat(),
                ));
            }

            // The pause goes before every turn but the first, so the recording
            // neither opens on silence nor ends on it.
            $samples .= ($index === 0 ? '' : $first->silence($gapSeconds)).$audio->samples;
        }

        // `$first` is set on the first pass and `$spoken` is non-empty, which
        // PHPStan follows — a null guard here is unreachable code it rejects.
        $joined = $first->withSamples($samples);

        return new RenderedDemo(
            $joined->toBytes(),
            $total,
            $joined->durationSeconds(),
            $joined->describeFormat(),
            $this->client->name() === 'fake',
        );
    }

    private function outputFormat(): string
    {
        return (string) config('restaurantline.demo.output_format', 'wav_24000');
    }
}
