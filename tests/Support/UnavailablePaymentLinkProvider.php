<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Order;
use App\Services\Payments\PaymentLink;
use App\Services\Payments\PaymentLinkException;
use App\Services\Payments\PaymentLinkProvider;

/**
 * A payment provider that is having a bad day, every time.
 *
 * Stands in for Stripe being down. The count is exposed so a test can tell the
 * difference between the job retrying and the job giving up, which is the
 * distinction the fallback to cash depends on.
 */
final class UnavailablePaymentLinkProvider implements PaymentLinkProvider
{
    public int $calls = 0;

    public function __construct(private readonly string $message = 'Stripe is having a moment.') {}

    public function createLink(Order $order): PaymentLink
    {
        $this->calls++;

        throw new PaymentLinkException($this->message);
    }

    public function name(): string
    {
        return 'unavailable';
    }
}
