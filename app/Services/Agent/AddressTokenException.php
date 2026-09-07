<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\AgentErrorCode;
use RuntimeException;

final class AddressTokenException extends RuntimeException
{
    public function __construct(public readonly AgentErrorCode $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
