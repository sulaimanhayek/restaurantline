<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Support\Str;

/**
 * A payment link that goes to this application instead of to Stripe.
 *
 * The default, and the reason a fresh clone has a working confirmation flow
 * before anybody has a Stripe account. The URL resolves: it is a real route in
 * this install that shows the order total and a button marking it paid, so the
 * whole path — link created, text sent, customer taps it, order becomes paid —
 * can be walked end to end on a laptop with no internet connection.
 *
 * It is refused outright in production. A fake payment page that anybody can
 * reach and press "pay" on is not a development convenience once real orders
 * are going through it, and the failure mode — a month of orders marked paid
 * that nobody was paid for — is quiet enough to survive a month.
 */
final class FakePaymentLinkProvider implements PaymentLinkProvider
{
    public function createLink(Order $order): PaymentLink
    {
        if (app()->isProduction() && ! (bool) config('restaurantline.payments.allow_fake_in_production', false)) {
            throw new PaymentLinkException(
                'The fake payment provider is refusing to run in production. Set PAYMENT_DRIVER=stripe, '
                .'or PAYMENTS_ALLOW_FAKE_IN_PRODUCTION=true if this install genuinely takes no card payments.',
            );
        }

        $reference = 'fake_'.Str::lower(Str::random(24));

        return new PaymentLink(
            url: route('payments.fake', ['reference' => $reference]),
            reference: $reference,
            expiresAt: $order->restaurant->paymentLinkExpiry(),
        );
    }

    public function name(): string
    {
        return 'fake';
    }
}
