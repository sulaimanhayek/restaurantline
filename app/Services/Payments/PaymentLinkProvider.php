<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;

/**
 * Creating a link the customer can pay an order at.
 *
 * Note the shape of this interface, because it is the point: it takes an order
 * and returns a URL. There is no method here that accepts a card number, an
 * expiry date or a CVC, and there is not going to be one. Card details are
 * entered by the customer, on the provider's own page, on their own phone.
 *
 * See README.md in this directory for why that is not negotiable.
 */
interface PaymentLinkProvider
{
    /**
     * @throws PaymentLinkException when no link could be created.
     */
    public function createLink(Order $order): PaymentLink;

    /**
     * The name recorded against the reference on the order.
     */
    public function name(): string;
}
