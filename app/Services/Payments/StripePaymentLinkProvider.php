<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A Stripe Checkout Session, used as a pay-by-link page.
 *
 * Switched on with PAYMENT_DRIVER=stripe. Checkout rather than a PaymentIntent
 * and a card form of our own, entirely deliberately: Checkout is a page hosted
 * by Stripe, on Stripe's domain, and the card number is typed into it by the
 * customer. No card data touches this server, which is what keeps a restaurant
 * running this boilerplate inside SAQ A rather than inside a compliance
 * programme they did not sign up for. See README.md in this directory.
 *
 * The `stripe/stripe-php` SDK is not used, for consistency with the rest of the
 * repo: one POST with form encoding is not worth a dependency, and the request
 * below shows a forker exactly what is being sent.
 */
final class StripePaymentLinkProvider implements PaymentLinkProvider
{
    private const ENDPOINT = 'https://api.stripe.com/v1/checkout/sessions';

    public function createLink(Order $order): PaymentLink
    {
        $secret = (string) config('restaurantline.payments.stripe.secret', '');

        if ($secret === '') {
            throw new PaymentLinkException('Stripe payment driver selected but STRIPE_SECRET is empty.');
        }

        // Stripe will not accept a session expiring in under 30 minutes or in
        // over 24 hours, and a restaurant is free to set a TTL either side of
        // that. Clamping here rather than in the model keeps Stripe's rule with
        // Stripe, and the clamped value is what gets stored on the order so the
        // dashboard shows the expiry that actually applies.
        $expiresAt = $this->clamp($order->restaurant->paymentLinkExpiry());

        try {
            $response = Http::asForm()
                ->withToken($secret)
                ->timeout((int) config('restaurantline.payments.timeout', 15))
                ->post(self::ENDPOINT, $this->parameters($order, $expiresAt?->getTimestamp()));
        } catch (ConnectionException $exception) {
            throw new PaymentLinkException('Could not reach Stripe: '.$exception->getMessage(), previous: $exception);
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        if ($response->failed()) {
            $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
            $message = is_string($error['message'] ?? null)
                ? $error['message']
                : 'Stripe returned HTTP '.$response->status().'.';

            Log::warning('Stripe rejected a Checkout Session.', [
                'status' => $response->status(),
                'order_number' => $order->order_number,
                'message' => $message,
            ]);

            throw new PaymentLinkException($message);
        }

        $url = $payload['url'] ?? null;
        $id = $payload['id'] ?? null;

        if (! is_string($url) || ! is_string($id)) {
            throw new PaymentLinkException('Stripe accepted the request but returned no Checkout URL.');
        }

        return new PaymentLink($url, $id, $expiresAt);
    }

    public function name(): string
    {
        return 'stripe';
    }

    /**
     * Stripe's form encoding for nested data is `line_items[0][price_data]`.
     * Spelled out rather than generated, because the shape of this array is the
     * part a forker will want to change — adding tax behaviour, say — and a
     * clever flattener hides it.
     *
     * @return array<string, string|int>
     */
    private function parameters(Order $order, ?int $expiresAt): array
    {
        $restaurant = $order->restaurant;

        $parameters = [
            'mode' => 'payment',
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => strtolower($restaurant->currency),
            // One line for the whole order rather than a line per dish. The
            // customer has already agreed the total on the phone and had it
            // read back; an itemised Stripe page invites them to re-audit it on
            // the doorstep, and the itemisation that matters is on the receipt.
            'line_items[0][price_data][product_data][name]' => sprintf('%s — order %s', $restaurant->name, $order->order_number),
            'line_items[0][price_data][unit_amount]' => $order->total,

            'success_url' => route('payments.return', ['order' => $order->order_number]),
            'cancel_url' => route('payments.return', ['order' => $order->order_number]),

            // What the webhook will quote back. Looking an order up by its own
            // number rather than by a Stripe id means a session recreated by
            // hand in the Stripe dashboard still lands on the right order.
            'client_reference_id' => $order->order_number,
            'metadata[order_number]' => $order->order_number,
            'metadata[restaurant_id]' => (string) $restaurant->id,
        ];

        if ($expiresAt !== null) {
            // Stripe requires at least 30 minutes into the future, and rejects
            // anything beyond 24 hours.
            $parameters['expires_at'] = $expiresAt;
        }

        return $parameters;
    }

    /**
     * Hold an expiry inside the window Stripe accepts.
     */
    private function clamp(?CarbonImmutable $expiresAt): ?CarbonImmutable
    {
        if ($expiresAt === null) {
            return null;
        }

        $now = CarbonImmutable::now();

        // A minute of headroom on the lower bound: the request takes a moment
        // to reach Stripe, and `exactly 30 minutes` measured there is 30
        // minutes minus the round trip.
        return $expiresAt
            ->max($now->addMinutes(31))
            ->min($now->addHours(24)->subMinute());
    }
}
