<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Carbon\CarbonImmutable;

/**
 * A URL the customer can pay at, and the provider's handle on it.
 *
 * The reference is what a webhook will later arrive quoting, so it is stored on
 * the order and is the only thing tying an incoming "this was paid" to a row in
 * this database. Everything else here is for the customer.
 */
final readonly class PaymentLink
{
    public function __construct(
        public string $url,
        public string $reference,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
