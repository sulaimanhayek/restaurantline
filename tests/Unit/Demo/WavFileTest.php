<?php

declare(strict_types=1);

use App\Services\Demo\DemoRenderException;
use App\Services\Demo\WavFile;

/**
 * A WAV with an extra chunk between `fmt ` and `data`.
 *
 * ElevenLabs does not send one today, but plenty of encoders do, and a parser
 * that assumes a 44-byte header would read this file's metadata as audio — a
 * burst of static at the top of every turn. Built by hand rather than with
 * `WavFile` so the test is not asking the code under test whether it is right.
 */
function wavWithListChunk(string $samples): string
{
    $list = 'LIST'.pack('V', 10).'INFOhello';  // Odd body, so a pad byte follows.
    $fmt = 'fmt '.pack('V', 16).pack('vvVVvv', 1, 1, 24_000, 48_000, 2, 16);
    $data = 'data'.pack('V', strlen($samples)).$samples;
    $body = 'WAVE'.$fmt.$list."\0".$data;

    return 'RIFF'.pack('V', strlen($body)).$body;
}

it('survives a round trip through parse and back', function (): void {
    $samples = random_bytes(4_800);

    $file = WavFile::parse(WavFile::silent(24_000, 0.1)->withSamples($samples)->toBytes());

    expect($file->channels)->toBe(1)
        ->and($file->sampleRate)->toBe(24_000)
        ->and($file->bitsPerSample)->toBe(16)
        ->and($file->samples)->toBe($samples);
});

it('walks the chunks rather than assuming where the audio starts', function (): void {
    $samples = random_bytes(960);

    $file = WavFile::parse(wavWithListChunk($samples));

    // The point of the test: a parser reading from byte 44 would return the
    // LIST chunk's bytes here, and they would be audible.
    expect($file->samples)->toBe($samples)
        ->and($file->sampleRate)->toBe(24_000);
});

it('refuses bytes that are not a WAV, and says which setting to look at', function (): void {
    expect(fn (): WavFile => WavFile::parse('ID3'.random_bytes(200)))
        ->toThrow(DemoRenderException::class, 'output_format');
});

it('reports duration from the sample count', function (): void {
    // 24000 Hz, 16-bit, mono is 48000 bytes a second.
    expect(WavFile::silent(24_000, 1.5)->durationSeconds())->toBe(1.5);
});

it('trims silence to whole sample frames', function (): void {
    $stereo = new WavFile(channels: 2, sampleRate: 44_100, bitsPerSample: 16, samples: '');

    // 44100 Hz stereo 16-bit is 4 bytes a frame; 0.001s is 176.4 bytes, and a
    // fraction of a frame shifts every sample after it into the wrong channel.
    expect(strlen($stereo->silence(0.001)) % 4)->toBe(0);
});

it('writes a header describing the samples it actually holds', function (): void {
    $bytes = WavFile::silent(24_000, 0.25)->withSamples(str_repeat("\0", 96_000))->toBytes();

    $riff = unpack('Vsize', substr($bytes, 4, 4));
    $data = unpack('Vsize', substr($bytes, 40, 4));

    // Not the length it was constructed with: a stitched demo is longer than
    // its first turn, and a stale header truncates it on playback.
    expect($data['size'])->toBe(96_000)
        ->and($riff['size'])->toBe(strlen($bytes) - 8);
});

it('tells two formats apart', function (): void {
    $a = WavFile::silent(24_000, 0.1);
    $b = WavFile::silent(44_100, 0.1);

    expect($a->sameFormatAs(WavFile::silent(24_000, 5.0)))->toBeTrue()
        ->and($a->sameFormatAs($b))->toBeFalse()
        ->and($a->describeFormat())->toBe('24000 Hz, 16-bit, mono');
});
