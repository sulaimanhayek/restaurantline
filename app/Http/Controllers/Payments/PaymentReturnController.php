<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Models\Restaurant;
use Illuminate\Contracts\View\View;

/**
 * Where a payment provider sends the customer once they are done.
 *
 * It shows a thank-you and nothing else, and does not look the order up. That
 * is deliberate: an order number is short, sequential-ish and printed on a
 * receipt, so a page keyed on one that displayed an address or a total would be
 * a way to read a stranger's order by guessing. The customer already knows what
 * they ordered.
 *
 * It also does not mark anything paid. A customer landing on a success URL is
 * evidence of a browser redirect, not of money moving — the webhook is the only
 * thing that knows, and it is the only thing allowed to say so.
 */
final class PaymentReturnController
{
    public function __invoke(string $order): View
    {
        // $order is not used, and not removed: it keeps the order number in the
        // URL, which is what makes a provider's success_url point somewhere
        // specific and what a customer forwarding the link to a friend's phone
        // hands over — nothing.
        return view('payments.return', [
            'restaurant' => Restaurant::current(),
        ]);
    }
}
