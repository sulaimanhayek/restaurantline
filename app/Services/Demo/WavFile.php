<?php

declare(strict_types=1);

namespace App\Services\Demo;

/**
 * A WAV file, parsed just far enough to glue two of them together.
 *
 * There is no ffmpeg in the container, none on a typical laptop, and no audio
 * library in this project's composer.json. Adding one of those so a demo
 * command can join some speech would be a large dependency for a small job.
 * WAV does not need one: it is a short header followed by the samples, so
 * joining two recordings of the same format is joining two strings and writing
 * a new header.
 *
 * That is the whole reason `kitchenline:demo:render` asks ElevenLabs for
 * `wav_24000`. MP3 frames can be concatenated too, but putting a pause between
 * two turns then means synthesising valid silent frames, which is exactly the
 * audio work this project does not do. 24kHz is also below the 44.1kHz line at
 * which ElevenLabs starts requiring a Pro subscription, so the free tier can
 * render a demo.
 *
 * Only the parts the renderer needs are modelled. This is not a general WAV
 * library and should not grow into one.
 */
final class WavFile
{
    public function __construct(
        public readonly int $channels,
        public readonly int $sampleRate,
        public readonly int $bitsPerSample,
        public readonly string $samples,
    ) {}

    /**
     * Walk the RIFF chunks for `fmt ` and `data`.
     *
     * Deliberately a walk rather than a read of the canonical 44-byte header:
     * a provider is entitled to put a `LIST` or `fact` chunk in front of the
     * samples, and a reader that assumes the data begins at byte 44 turns that
     * chunk's contents into a burst of noise at the start of the file.
     */
    public static function parse(string $bytes): self
    {
        if (strlen($bytes) < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') {
            throw new DemoRenderException(
                'That is not a WAV file — it has no RIFF/WAVE header. The renderer joins recordings by '
                .'their samples, which it can only do for wav_* output formats; check '
                .'`restaurantline.demo.output_format`.',
            );
        }

        $channels = null;
        $sampleRate = null;
        $bits = null;
        $samples = null;

        $offset = 12;
        $length = strlen($bytes);

        while ($offset + 8 <= $length) {
            $id = substr($bytes, $offset, 4);
            $header = unpack('Vsize', substr($bytes, $offset + 4, 4));
            $size = is_array($header) && is_int($header['size'] ?? null) ? $header['size'] : 0;
            $body = substr($bytes, $offset + 8, $size);

            if ($id === 'fmt ' && strlen($body) >= 16) {
                $fmt = unpack('vformat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbits', $body);

                if (is_array($fmt)) {
                    $channels = (int) $fmt['channels'];
                    $sampleRate = (int) $fmt['sampleRate'];
                    $bits = (int) $fmt['bits'];
                }
            }

            if ($id === 'data') {
                $samples = $body;
            }

            // RIFF chunks are word-aligned: an odd length is followed by a pad
            // byte that is not counted in the size.
            $offset += 8 + $size + ($size % 2);
        }

        if ($channels === null || $sampleRate === null || $bits === null || $samples === null) {
            throw new DemoRenderException(
                'That WAV file is missing its "fmt " or "data" chunk, so there is nothing to join.',
            );
        }

        return new self($channels, $sampleRate, $bits, $samples);
    }

    public function durationSeconds(): float
    {
        $rate = $this->bytesPerSecond();

        return $rate === 0 ? 0.0 : strlen($this->samples) / $rate;
    }

    /**
     * Silence in this file's own format — the pause between two turns.
     *
     * Trimmed to a whole number of sample frames. A stray half-sample shifts
     * every frame after it by one byte, which does not sound like a click; it
     * sounds like the rest of the recording has been replaced with static.
     */
    public function silence(float $seconds): string
    {
        $blockAlign = max(1, $this->channels * intdiv($this->bitsPerSample, 8));
        $bytes = (int) round($this->bytesPerSecond() * max(0.0, $seconds));

        return str_repeat("\0", max(0, $bytes - ($bytes % $blockAlign)));
    }

    public function withSamples(string $samples): self
    {
        return new self($this->channels, $this->sampleRate, $this->bitsPerSample, $samples);
    }

    public function sameFormatAs(self $other): bool
    {
        return $this->channels === $other->channels
            && $this->sampleRate === $other->sampleRate
            && $this->bitsPerSample === $other->bitsPerSample;
    }

    public function describeFormat(): string
    {
        return sprintf(
            '%d Hz, %d-bit, %s',
            $this->sampleRate,
            $this->bitsPerSample,
            $this->channels === 1 ? 'mono' : $this->channels.' channels',
        );
    }

    /**
     * The canonical 44-byte header, then the samples.
     *
     * Written rather than copied from the input on purpose: the output is one
     * file of a known length, and the input's `RIFF` and `data` sizes describe
     * a shorter one.
     */
    public function toBytes(): string
    {
        $blockAlign = $this->channels * intdiv($this->bitsPerSample, 8);
        $dataSize = strlen($this->samples);

        return 'RIFF'
            .pack('V', 36 + $dataSize)
            .'WAVE'
            .'fmt '
            .pack('V', 16)
            .pack('v', 1)  // PCM, uncompressed.
            .pack('v', $this->channels)
            .pack('V', $this->sampleRate)
            .pack('V', $this->sampleRate * $blockAlign)
            .pack('v', $blockAlign)
            .pack('v', $this->bitsPerSample)
            .'data'
            .pack('V', $dataSize)
            .$this->samples;
    }

    /**
     * A silent mono file — what the fake driver "speaks" with.
     */
    public static function silent(int $sampleRate, float $seconds): self
    {
        $file = new self(1, $sampleRate, 16, '');

        return $file->withSamples($file->silence($seconds));
    }

    private function bytesPerSecond(): int
    {
        return $this->sampleRate * $this->channels * intdiv($this->bitsPerSample, 8);
    }
}
