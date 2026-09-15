<?php

declare(strict_types=1);

namespace App\Services\Evals;

/**
 * One thing a scenario asserted, and whether it held.
 *
 * A scenario produces a list of these rather than throwing on the first
 * failure. An eval that stops at the first problem tells you the agent got the
 * burger wrong; an eval that runs to the end tells you it got the burger wrong
 * *and* never read the order back, which is a different conversation with
 * whoever wrote the prompt.
 */
final readonly class Check
{
    private function __construct(
        public bool $passed,
        public string $label,
        public ?string $detail = null,
    ) {}

    public static function pass(string $label): self
    {
        return new self(true, $label);
    }

    public static function fail(string $label, string $detail): self
    {
        return new self(false, $label, $detail);
    }

    /**
     * The commonest shape: something was expected, something else arrived.
     */
    public static function equals(string $label, mixed $expected, mixed $actual): self
    {
        return $expected === $actual
            ? self::pass($label)
            : self::fail($label, sprintf('expected %s, got %s', self::render($expected), self::render($actual)));
    }

    public static function render(mixed $value): string
    {
        return match (true) {
            is_string($value) => '"'.$value.'"',
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
