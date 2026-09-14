<?php

declare(strict_types=1);

namespace App\Services\Evals;

use RuntimeException;

/**
 * A scenario file that cannot be read or does not make sense.
 *
 * Distinct from a scenario that fails: a failing scenario is the harness
 * working, and this is the harness being unable to start.
 */
final class EvalScenarioException extends RuntimeException
{
    public static function at(string $where, string $problem): self
    {
        return new self(sprintf('%s: %s', $where, $problem));
    }
}
