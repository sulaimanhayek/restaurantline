<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The page the fake payment driver's links point at.
 *
 * It exists so the confirmation flow can be walked end to end on a laptop with
 * no Stripe account and no internet connection: confirm an order, get the text
 * in the dashboard, open the link, press the button, watch the order go green.
 * That path is the one a forker will try in their first hour, and a link in it
 * that 404s is where they conclude the repo is half-finished.
 *
 * It is not reachable in production. FakePaymentLinkProvider refuses to create
 * a link there, so no such reference can exist, and both actions below are
 * guarded independently anyway — this route is a "mark an order paid" button
 * with no authentication in front of it, and one guard is not enough for that.
 */
final class FakeCheckoutController
{
    public function show(string $reference): View
    {
        $order = $this->order($reference);

        return view('payments.fake', [
            'order' => $order,
            'paid' => $order->payment_status === PaymentStatus::Paid,
        ]);
    }

    public function pay(string $reference): RedirectResponse
    {
        $order = $this->order($reference);

        if ($order->payment_status !== PaymentStatus::Paid) {
            $order->update([
                'payment_status' => PaymentStatus::Paid,
                'paid_at' => now(),
                'amount_paid' => $order->total,
            ]);
        }

        return redirect()->route('payments.return', ['order' => $order->order_number]);
    }

    /**
     * The order this reference belongs to, or a 404.
     *
     * Refuses outright when the fake driver is not the configured one, rather
     * than merely finding nothing. An install that switched to Stripe still has
     * old `fake_…` references in its orders table, and those links must stop
     * working the moment real money is involved.
     */
    private function order(string $reference): Order
    {
        $driverIsFake = (string) config('restaurantline.payments.driver', 'fake') === 'fake';

        if (! $driverIsFake || app()->isProduction()) {
            throw new NotFoundHttpException;
        }

        $order = Order::query()
            ->with('restaurant')
            ->where('payment_reference', $reference)
            ->first();

        if (! $order instanceof Order || ! str_starts_with($reference, 'fake_')) {
            throw new NotFoundHttpException;
        }

        return $order;
    }
}
