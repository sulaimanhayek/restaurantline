<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\AgentErrorCode;
use RuntimeException;

/**
 * A cart the restaurant cannot fulfil as asked.
 *
 * Carries everything the endpoint needs to answer in one throw: the code the
 * agent branches on, the sentence it says, and whatever would help it recover —
 * the alternatives to offer, the item it choked on.
 */
final class CartAssemblyException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly AgentErrorCode $errorCode,
        public readonly string $say,
        public readonly array $data = [],
    ) {
        parent::__construct($say);
    }
}
