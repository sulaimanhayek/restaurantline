<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Services\Payments\PaymentLink;

/**
 * The words in the text message a customer gets after the call.
 *
 * Kept apart from the job that sends them because this is the part somebody
 * forking this repo will actually want to change — it is the restaurant's voice
 * — and it should be findable without reading a queue worker.
 *
 * Three rules the wording follows. Keep the order number first, because that is
 * what the customer will be asked for when they ring back. Keep the whole thing
 * inside one segment where possible: an SMS is 160 GSM-7 characters, a long
 * message is silently billed as two or three, and a restaurant doing three
 * hundred orders a week notices. And never put anything in here that would be
 * embarrassing on a lock screen somebody else is looking at.
 */
final class OrderMessages
{
    public static function confirmation(Order $order, ?PaymentLink $link = null): string
    {
        $restaurant = $order->restaurant;

        $lines = [
            sprintf('%s: order %s confirmed, %s.', $restaurant->name, $order->order_number, $order->totalMoney()->format()),
            self::timing($order),
        ];

        if ($order->payment_method === PaymentMethod::CardLink && $link !== null) {
            $lines[] = 'Pay here: '.$link->url;
        } elseif ($order->payment_method === PaymentMethod::Cash) {
            $lines[] = $order->isDelivery() ? 'Please have cash ready for the driver.' : 'Pay by cash when you collect.';
        }

        return implode("\n", array_filter($lines));
    }

    /**
     * A follow-up carrying only the link, for the dashboard's "send again"
     * button and for an order whose first text failed.
     *
     * Shorter than the confirmation on purpose: the customer already knows what
     * they ordered and is looking for the thing to tap.
     */
    public static function paymentLink(Order $order, PaymentLink $link): string
    {
        return sprintf(
            '%s: pay %s for order %s here: %s',
            $order->restaurant->name,
            $order->totalMoney()->format(),
            $order->order_number,
            $link->url,
        );
    }

    /**
     * When to expect the food, written rather than spoken.
     *
     * `Order::spokenWait()` exists for the agent and says "in about 20
     * minutes", which reads oddly in a text sent a minute later and is wrong by
     * the time somebody reads it on the bus. A clock time is what a written
     * message should carry.
     */
    private static function timing(Order $order): string
    {
        $readyAt = $order->estimated_ready_at;

        if ($readyAt === null) {
            return '';
        }

        $local = $readyAt->copy()->setTimezone($order->restaurant->timezone);
        $verb = $order->isDelivery() ? 'Delivery' : 'Ready';

        return $local->isSameDay($order->restaurant->now())
            ? sprintf('%s around %s.', $verb, $local->format('H:i'))
            : sprintf('%s around %s on %s.', $verb, $local->format('H:i'), $local->format('D j M'));
    }
}
