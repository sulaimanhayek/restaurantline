<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * What a provider said when handed one message.
 *
 * A value object rather than a thrown exception on failure, because a text that
 * did not send is a normal operational event — a mistyped number, a landline, a
 * provider having a bad morning — and the caller has already hung up. The order
 * still stands; somebody in the dashboard needs to be able to see what went
 * wrong and press send again.
 */
final readonly class SmsResult
{
    private function __construct(
        public bool $sent,
        public ?string $providerMessageId,
        public ?string $error,
    ) {}

    public static function sent(?string $providerMessageId = null): self
    {
        return new self(true, $providerMessageId, null);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}
