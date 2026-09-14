<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Order;

/**
 * Checkout Session events, shaped as Stripe sends them.
 *
 * Field names verified against Stripe's Checkout Session object: `id`,
 * `object`, `client_reference_id`, `payment_status`, `status`, `amount_total`,
 * `currency`, `metadata`. The distinction that matters most here is `status`
 * versus `payment_status` — a session can be `complete` and `unpaid`, and an
 * endpoint reading the wrong one marks orders paid that nobody has paid for.
 */
final class StripePayloads
{
    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    public static function event(string $type, array $session = []): array
    {
        return [
            'id' => 'evt_'.bin2hex(random_bytes(8)),
            'object' => 'event',
            'api_version' => '2025-08-27.basil',
            'created' => now()->getTimestamp(),
            'type' => $type,
            'data' => ['object' => $session],
        ];
    }

    /**
     * A session for a real order in the database.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function session(Order $order, array $overrides = []): array
    {
        return array_replace([
            'id' => $order->payment_reference ?? 'cs_test_'.bin2hex(random_bytes(8)),
            'object' => 'checkout.session',
            'client_reference_id' => $order->order_number,
            'currency' => strtolower($order->restaurant->currency),
            'amount_total' => $order->total,
            'status' => 'complete',
            'payment_status' => 'paid',
            'metadata' => [
                'order_number' => $order->order_number,
                'restaurant_id' => (string) $order->restaurant_id,
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function completed(Order $order, array $overrides = []): array
    {
        return self::event('checkout.session.completed', self::session($order, $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function expired(Order $order, array $overrides = []): array
    {
        return self::event('checkout.session.expired', self::session($order, array_replace([
            'status' => 'expired',
            'payment_status' => 'unpaid',
        ], $overrides)));
    }
}
