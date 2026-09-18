<?php

declare(strict_types=1);

namespace App\Services\Demo;

/**
 * What came back from a render: the file, and enough to describe it.
 */
final class RenderedDemo
{
    public function __construct(
        public readonly string $wav,
        public readonly int $turns,
        public readonly float $seconds,
        public readonly string $format,
        public readonly bool $silent,
    ) {}

    public function describeLength(): string
    {
        return sprintf('%d:%02d', (int) ($this->seconds / 60), (int) round(fmod($this->seconds, 60)));
    }
}
