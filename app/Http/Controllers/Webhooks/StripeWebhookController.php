<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Stripe telling us a payment link was used.
 *
 * This is the only way an order reaches `paid`. Nothing in the voice flow, the
 * dashboard's normal path or the agent tools can set it, because none of them
 * knows whether money moved — only the payment provider does.
 *
 * The signature is checked by middleware before anything here runs. Everything
 * below assumes the payload is genuine and none of it assumes the payload is
 * the shape this code was written against: Stripe adds fields, and a webhook
 * that fails on an unfamiliar one produces a retry storm and an order stuck
 * unpaid for a reason nobody can see.
 *
 * Handled inline rather than queued, unlike the ElevenLabs one. The work is a
 * single indexed lookup and an update — measured in milliseconds, well inside
 * Stripe's timeout — and doing it inline means the "paid" badge is already true
 * by the time the customer's browser lands back on the return page.
 *
 * @see docs/DECISIONS.md #0039
 */
final class StripeWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $type = $request->input('type');
        $object = $request->input('data.object');

        if (! is_string($type) || ! is_array($object)) {
            Log::warning('A signed Stripe webhook arrived without a recognisable envelope.');

            // 200, not 400. The signature was good, so this came from Stripe,
            // and re-delivering it will not make it parseable.
            return response()->json(['ok' => true, 'handled' => false]);
        }

        $handled = match ($type) {
            'checkout.session.completed' => $this->completed($object),
            'checkout.session.expired' => $this->expired($object),
            // Everything else is ignored on purpose. A Stripe account emits
            // dozens of event types and an endpoint subscribed to all of them
            // should shrug at the ones it does not want.
            default => false,
        };

        return response()->json(['ok' => true, 'handled' => $handled]);
    }

    /**
     * The customer paid.
     *
     * @param  array<string, mixed>  $session
     */
    private function completed(array $session): bool
    {
        $order = $this->order($session);

        if (! $order instanceof Order) {
            return false;
        }

        // Stripe sends `unpaid` for a session completed in a payment mode that
        // settles later. Trusting `status: complete` alone would mark an order
        // paid that has not been.
        if (($session['payment_status'] ?? null) !== 'paid') {
            Log::info('A Checkout Session completed without being paid.', [
                'order_number' => $order->order_number,
                'payment_status' => $session['payment_status'] ?? null,
            ]);

            return false;
        }

        // Redelivery is normal and this endpoint will see the same event more
        // than once. Returning early keeps `paid_at` as the first time it was
        // true rather than the last time Stripe mentioned it.
        if ($order->payment_status === PaymentStatus::Paid) {
            return true;
        }

        $order->update([
            'payment_status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'amount_paid' => is_numeric($session['amount_total'] ?? null) ? (int) $session['amount_total'] : $order->total,
        ]);

        return true;
    }

    /**
     * The link timed out unused.
     *
     * Back to `unpaid`, which is what it genuinely is. Not `failed`: nothing
     * failed, the customer simply did not pay yet, and somebody in the
     * dashboard can send them a fresh link.
     *
     * @param  array<string, mixed>  $session
     */
    private function expired(array $session): bool
    {
        $order = $this->order($session);

        if (! $order instanceof Order || $order->payment_status !== PaymentStatus::LinkSent) {
            return false;
        }

        $order->update([
            'payment_status' => PaymentStatus::Unpaid,
            'payment_link_url' => null,
        ]);

        return true;
    }

    /**
     * Find the order a Checkout Session belongs to.
     *
     * `client_reference_id` first, because it survives a session recreated by
     * hand in the Stripe dashboard; the stored session id second, for anything
     * created before that was set. An event for an order this install has never
     * heard of is logged rather than ignored silently — it usually means two
     * environments are pointed at the same Stripe account, which is worth
     * finding out about early.
     *
     * @param  array<string, mixed>  $session
     */
    private function order(array $session): ?Order
    {
        $reference = $session['client_reference_id'] ?? null;

        $order = is_string($reference) && $reference !== ''
            ? Order::query()->where('order_number', $reference)->first()
            : null;

        if (! $order instanceof Order) {
            $id = $session['id'] ?? null;

            $order = is_string($id) && $id !== ''
                ? Order::query()->where('payment_reference', $id)->first()
                : null;
        }

        if (! $order instanceof Order) {
            Log::warning('A Stripe webhook referenced an order this install does not have.', [
                'client_reference_id' => is_string($reference) ? $reference : null,
            ]);
        }

        return $order;
    }
}
