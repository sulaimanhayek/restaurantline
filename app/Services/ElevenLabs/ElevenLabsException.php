<?php

declare(strict_types=1);

namespace App\Services\ElevenLabs;

use RuntimeException;

/**
 * The API said no.
 *
 * Carries the status and the body ElevenLabs sent, because the useful half of a
 * failed provision is almost always in the body — a 422 naming the one property
 * of one tool that is malformed is worth more than "provisioning failed".
 */
class ElevenLabsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $body = null,
    ) {
        parent::__construct($message);
    }
}
