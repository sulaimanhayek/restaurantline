<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use Tests\Support\FakeStripeSignature;
use Tests\Support\StripePayloads;

/**
 * The only way an order in this application reaches `paid`.
 *
 * Which makes this endpoint the single most attractive URL in the repo: a
 * request that gets through it is free food. Every test in the first block is a
 * request that must not be allowed to change a payment status, and the
 * signature is generated rather than stubbed so the thing under test is the
 * middleware that actually runs in production.
 *
 * @see docs/DECISIONS.md #0039
 */
beforeEach(function (): void {
    $this->restaurant = restaurant();

    config(['restaurantline.payments.stripe.webhook_secret' => 'whsec_stripe_test_secret']);
});

/**
 * An order with a link out, waiting to be paid for.
 *
 * @param  array<string, mixed>  $attributes
 */
function awaitingPayment(array $attributes = []): Order
{
    /** @var Order $order */
    $order = Order::factory()
        ->for(test()->restaurant)
        ->for(Customer::factory()->for(test()->restaurant))
        ->confirmed()
        ->create(array_replace([
            'payment_method' => PaymentMethod::CardLink,
            'payment_status' => PaymentStatus::LinkSent,
            'payment_reference' => 'cs_test_'.bin2hex(random_bytes(8)),
            'payment_link_url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
        ], $attributes));

    return $order;
}

describe('proving it came from Stripe', function (): void {
    it('accepts a correctly signed event', function (): void {
        postStripeWebhook(StripePayloads::completed(awaitingPayment()))
            ->assertOk()
            ->assertJsonPath('handled', true);
    });

    it('rejects a request with no signature at all', function (): void {
        $order = awaitingPayment();

        $this->postJson('/webhooks/stripe', StripePayloads::completed($order))
            ->assertUnauthorized();

        expect($order->refresh()->payment_status)->toBe(PaymentStatus::LinkSent);
    });

    it('rejects a signature computed with the wrong secret', function (): void {
        $order = awaitingPayment();

        postStripeWebhook(StripePayloads::completed($order), secrets: ['whsec_not_the_secret'])
            ->assertUnauthorized();

        expect($order->refresh()->payment_status)->toBe(PaymentStatus::LinkSent);
    });

    /*
     * The attack the signature exists to stop: a genuine, correctly signed
     * event with the amount edited. Everything about the request looks right
     * except the one part that matters.
     */
    it('rejects a body edited after signing', function (): void {
        $order = awaitingPayment();
        $signed = (string) json_encode(StripePayloads::completed($order, ['amount_total' => 100]));
        $header = FakeStripeSignature::header($signed, ['whsec_stripe_test_secret']);

        $this->call(
            'POST',
            '/webhooks/stripe',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $header],
            content: str_replace('"amount_total":100', '"amount_total":1', $signed),
        )->assertUnauthorized();

        expect($order->refresh()->payment_status)->toBe(PaymentStatus::LinkSent);
    });

    it('rejects a malformed signature header', function (): void {
        postStripeWebhook(StripePayloads::completed(awaitingPayment()), signature: 'not-a-signature')
            ->assertUnauthorized();
    });

    /*
     * A replay: a real event, correctly signed, captured and sent again days
     * later. The timestamp is inside the signed payload, so an attacker cannot
     * move it without breaking the hash.
     */
    it('rejects an event signed outside the tolerance', function (): void {
        postStripeWebhook(
            StripePayloads::completed(awaitingPayment()),
            timestamp: now()->subHour()->getTimestamp(),
        )->assertUnauthorized();
    });

    /*
     * During a secret rotation Stripe signs with both the old secret and the
     * new one, and the header carries a `v1` for each. A verifier that read
     * only the first would reject half the traffic for the length of the
     * rollover — a payment endpoint silently failing for hours.
     */
    it('accepts an event signed during a secret rotation', function (): void {
        postStripeWebhook(
            StripePayloads::completed(awaitingPayment()),
            secrets: ['whsec_previous_secret', 'whsec_stripe_test_secret'],
        )->assertOk();
    });

    /*
     * A fresh install has no secret, and an install with no secret must not be
     * accepting payment notifications from strangers. Skipping the check when
     * unconfigured would make this endpoint an open "mark as paid" button.
     */
    it('rejects everything when no webhook secret is configured', function (): void {
        config(['restaurantline.payments.stripe.webhook_secret' => null]);

        postStripeWebhook(StripePayloads::completed(awaitingPayment()))
            ->assertUnauthorized();
    });
});

describe('a payment that went through', function (): void {
    it('marks the order paid, with the amount Stripe actually took', function (): void {
        $order = awaitingPayment();

        postStripeWebhook(StripePayloads::completed($order))->assertOk();

        $order->refresh();

        expect($order->payment_status)->toBe(PaymentStatus::Paid)
            ->and($order->paid_at)->not->toBeNull()
            ->and($order->amount_paid)->toBe($order->total);
    });

    /*
     * Redelivery is normal — Stripe retries anything it did not get a 2xx for,
     * and a webhook endpoint sees the same event more than once as a matter of
     * course. `paid_at` should be the first time it was true, not the last time
     * Stripe mentioned it.
     */
    it('keeps the first paid_at when the same event arrives twice', function (): void {
        $order = awaitingPayment();
        $event = StripePayloads::completed($order);

        postStripeWebhook($event)->assertOk();
        $paidAt = $order->refresh()->paid_at;

        $this->travel(5)->minutes();

        postStripeWebhook($event)->assertOk()->assertJsonPath('handled', true);

        expect($order->refresh()->paid_at?->equalTo($paidAt))->toBeTrue();
    });

    /*
     * Stripe sends `status: complete` with `payment_status: unpaid` for a
     * session settling later. Trusting `status` alone marks an order paid that
     * has not been.
     */
    it('does not mark an order paid on a completed but unpaid session', function (): void {
        $order = awaitingPayment();

        postStripeWebhook(StripePayloads::completed($order, ['payment_status' => 'unpaid']))
            ->assertOk()
            ->assertJsonPath('handled', false);

        expect($order->refresh()->payment_status)->toBe(PaymentStatus::LinkSent);
    });

    it('finds the order by the stored session id when there is no client reference', function (): void {
        $order = awaitingPayment();

        postStripeWebhook(StripePayloads::completed($order, [
            'id' => (string) $order->payment_reference,
            'client_reference_id' => null,
        ]))->assertOk();

        expect($order->refresh()->payment_status)->toBe(PaymentStatus::Paid);
    });

    /*
     * Two environments pointed at the same Stripe account is the usual cause,
     * and it is worth finding out about early — but not worth a non-2xx, which
     * would have Stripe retrying an event this install can never handle.
     */
    it('answers a session for an order it has never heard of without failing', function (): void {
        $order = awaitingPayment();

        postStripeWebhook(StripePayloads::completed($order, [
            'id' => 'cs_test_somebody_elses',
            'client_reference_id' => 'NOT-AN-ORDER',
        ]))->assertOk()->assertJsonPath('handled', false);

        expect($order->refresh()->payment_status)->toBe(PaymentStatus::LinkSent);
    });
});

describe('a link that timed out', function (): void {
    /*
     * Back to `unpaid`, which is what it genuinely is: nothing failed, the
     * customer simply did not pay, and somebody in the dashboard can send a
     * fresh link. The dead URL is cleared so nobody hands it out again.
     */
    it('puts the order back to unpaid and drops the dead link', function (): void {
        $order = awaitingPayment();

        postStripeWebhook(StripePayloads::expired($order))->assertOk()->assertJsonPath('handled', true);

        $order->refresh();

        expect($order->payment_status)->toBe(PaymentStatus::Unpaid)
            ->and($order->payment_link_url)->toBeNull();
    });

    it('leaves an order that was already paid alone', function (): void {
        $order = awaitingPayment(['payment_status' => PaymentStatus::Paid]);

        postStripeWebhook(StripePayloads::expired($order))->assertOk()->assertJsonPath('handled', false);

        expect($order->refresh()->payment_status)->toBe(PaymentStatus::Paid);
    });
});

describe('everything else Stripe sends', function (): void {
    /*
     * A Stripe account emits dozens of event types and an endpoint subscribed
     * to a few of them should shrug at the rest rather than 400, which would
     * put the endpoint into Stripe's failing state and start a retry storm.
     */
    it('shrugs at an event type it does not handle', function (): void {
        postStripeWebhook(StripePayloads::event('payment_intent.succeeded', ['id' => 'pi_123']))
            ->assertOk()
            ->assertJsonPath('handled', false);
    });

    it('shrugs at a signed payload that is not an event envelope at all', function (): void {
        postStripeWebhook(['hello' => 'world'])
            ->assertOk()
            ->assertJsonPath('handled', false);
    });
});
