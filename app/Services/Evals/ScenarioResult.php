<?php

declare(strict_types=1);

namespace App\Services\Evals;

/**
 * What one scenario did, and whether it was what it was supposed to do.
 *
 * `error` and a failed check are different things and the command prints them
 * differently. A failed check is the harness working: the agent, or the
 * application behind it, did the wrong thing and here is what it should have
 * done. An error is the harness not working — a placeholder nothing bound, a
 * tool name that does not exist, ElevenLabs refusing the request — and is a
 * problem with the eval rather than a report about the product.
 *
 * The distinction earns its keep in CI, where a run of failures all reading
 * "expected ok, got false" is a regression worth stopping for, and a run of
 * errors all reading "no such tool" is one renamed route.
 */
final readonly class ScenarioResult
{
    /**
     * @param  list<Check>  $checks
     * @param  list<string>  $log  Human-readable lines: the calls made, or the transcript.
     */
    public function __construct(
        public Scenario $scenario,
        public array $checks,
        public array $log,
        public float $seconds,
        public ?string $error = null,
    ) {}

    public static function failedToRun(Scenario $scenario, string $error, float $seconds = 0.0): self
    {
        return new self($scenario, [], [], $seconds, $error);
    }

    public function passed(): bool
    {
        return $this->error === null && $this->failures() === [];
    }

    /**
     * @return list<Check>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->checks, static fn (Check $check): bool => ! $check->passed));
    }
}
