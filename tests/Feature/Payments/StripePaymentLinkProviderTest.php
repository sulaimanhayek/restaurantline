<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Order;
use App\Services\Payments\FakePaymentLinkProvider;
use App\Services\Payments\PaymentLinkException;
use App\Services\Payments\PaymentLinkProvider;
use App\Services\Payments\StripePaymentLinkProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Asking Stripe for a page the customer can pay on.
 *
 * A Checkout Session, not a PaymentIntent and a card form of our own: the page
 * is hosted by Stripe, on Stripe's domain, and the card number is typed into it
 * by the customer on their own phone. No card data reaches this server, which
 * is what keeps a restaurant running this boilerplate inside SAQ A. The test
 * that matters most in this file is the one asserting what is *not* in the
 * request.
 *
 * @see app/Services/Payments/README.md
 */
beforeEach(function (): void {
    $this->restaurant = restaurant(['name' => 'Ember Kitchen', 'currency' => 'GBP', 'payment_link_ttl_minutes' => 60]);

    config([
        'restaurantline.payments.driver' => 'stripe',
        'restaurantline.payments.stripe.secret' => 'sk_test_123',
    ]);
});

/**
 * Stripe's response to a Checkout Session it created.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stripeSession(array $overrides = []): array
{
    return array_replace([
        'id' => 'cs_test_a1b2c3',
        'object' => 'checkout.session',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_a1b2c3',
        'status' => 'open',
        'payment_status' => 'unpaid',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function linkableOrder(array $attributes = []): Order
{
    /** @var Order $order */
    $order = Order::factory()
        ->for(test()->restaurant)
        ->for(Customer::factory()->for(test()->restaurant))
        ->confirming()
        ->create(array_replace(['order_number' => 'L-2356', 'subtotal' => 2450, 'total' => 2450], $attributes));

    return $order;
}

it('asks Stripe for a session for exactly this order', function (): void {
    Http::fake(['api.stripe.com/*' => Http::response(stripeSession())]);

    $link = (new StripePaymentLinkProvider)->createLink(linkableOrder());

    expect($link->url)->toBe('https://checkout.stripe.com/c/pay/cs_test_a1b2c3')
        ->and($link->reference)->toBe('cs_test_a1b2c3');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $request->hasHeader('Authorization', 'Bearer sk_test_123')
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
            && $request['mode'] === 'payment'
            && $request['line_items[0][price_data][currency]'] === 'gbp'
            && $request['line_items[0][price_data][unit_amount]'] === 2450
            && $request['line_items[0][price_data][product_data][name]'] === 'Ember Kitchen — order L-2356'
            && $request['client_reference_id'] === 'L-2356';
    });
});

/*
 * The rule the whole subsystem exists to serve, asserted against the bytes on
 * the wire. If a card field ever appears in this request, the restaurant has
 * quietly left SAQ A and nobody will notice until an audit.
 */
it('sends Stripe no card data of any kind', function (): void {
    Http::fake(['api.stripe.com/*' => Http::response(stripeSession())]);

    (new StripePaymentLinkProvider)->createLink(linkableOrder());

    Http::assertSent(function (Request $request): bool {
        $body = strtolower((string) $request->body());

        foreach (['card_number', 'card[number]', 'cvc', 'exp_month', 'exp_year', 'payment_method_data'] as $forbidden) {
            if (str_contains($body, $forbidden)) {
                return false;
            }
        }

        return true;
    });
});

/*
 * The customer comes back to a page that says thank you and nothing else, and
 * both outcomes go to the same place: somebody who abandoned the payment does
 * not need a different page, they need the link they still have in their
 * texts.
 */
it('sends the customer back here afterwards, either way', function (): void {
    Http::fake(['api.stripe.com/*' => Http::response(stripeSession())]);

    (new StripePaymentLinkProvider)->createLink(linkableOrder());

    $expected = route('payments.return', ['order' => 'L-2356']);

    Http::assertSent(fn (Request $request): bool => $request['success_url'] === $expected && $request['cancel_url'] === $expected);
});

describe('the expiry Stripe will actually accept', function (): void {
    /*
     * Stripe rejects a session expiring in under 30 minutes, and the TTL is a
     * restaurant setting somebody will reasonably set to 15. Clamping happens
     * here rather than in the model so Stripe's rule stays with Stripe — and
     * the clamped value is what gets returned, so the dashboard shows the
     * expiry that actually applies rather than the one that was asked for.
     */
    it('lifts a too-short expiry to the earliest Stripe allows', function (): void {
        Http::fake(['api.stripe.com/*' => Http::response(stripeSession())]);
        $this->restaurant->update(['payment_link_ttl_minutes' => 5]);

        $link = (new StripePaymentLinkProvider)->createLink(linkableOrder());

        expect($link->expiresAt?->getTimestamp())->toBeGreaterThanOrEqual(now()->addMinutes(30)->getTimestamp());

        Http::assertSent(fn (Request $request): bool => (int) $request['expires_at'] >= now()->addMinutes(30)->getTimestamp());
    });

    it('holds a too-long expiry inside the twenty-four hours Stripe allows', function (): void {
        Http::fake(['api.stripe.com/*' => Http::response(stripeSession())]);
        $this->restaurant->update(['payment_link_ttl_minutes' => 60 * 72]);

        $link = (new StripePaymentLinkProvider)->createLink(linkableOrder());

        expect($link->expiresAt?->getTimestamp())->toBeLessThan(now()->addHours(24)->getTimestamp());
    });

    it('sends no expiry at all when the restaurant does not want one', function (): void {
        Http::fake(['api.stripe.com/*' => Http::response(stripeSession())]);
        $this->restaurant->update(['payment_link_ttl_minutes' => 0]);

        $link = (new StripePaymentLinkProvider)->createLink(linkableOrder());

        expect($link->expiresAt)->toBeNull();

        Http::assertSent(fn (Request $request): bool => ! isset($request['expires_at']));
    });
});

describe('when Stripe will not play', function (): void {
    it('throws with the reason Stripe gave', function (): void {
        Http::fake(['api.stripe.com/*' => Http::response([
            'error' => ['message' => 'You cannot use a test key in live mode.', 'type' => 'invalid_request_error'],
        ], 400)]);

        expect(fn () => (new StripePaymentLinkProvider)->createLink(linkableOrder()))
            ->toThrow(PaymentLinkException::class, 'You cannot use a test key in live mode.');
    });

    it('throws when it cannot reach Stripe at all', function (): void {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        expect(fn () => (new StripePaymentLinkProvider)->createLink(linkableOrder()))
            ->toThrow(PaymentLinkException::class, 'Could not reach Stripe');
    });

    it('throws when Stripe accepts the request but returns no URL', function (): void {
        Http::fake(['api.stripe.com/*' => Http::response(stripeSession(['url' => null]))]);

        expect(fn () => (new StripePaymentLinkProvider)->createLink(linkableOrder()))
            ->toThrow(PaymentLinkException::class, 'returned no Checkout URL');
    });

    /*
     * Selecting the Stripe driver without a key is a deployment that took
     * orders and could not bill for any of them. Better to fail loudly at the
     * first link than to fall back to something that looks like it worked.
     */
    it('throws rather than guessing when STRIPE_SECRET is empty', function (): void {
        config(['restaurantline.payments.stripe.secret' => '']);
        Http::fake();

        expect(fn () => (new StripePaymentLinkProvider)->createLink(linkableOrder()))
            ->toThrow(PaymentLinkException::class, 'STRIPE_SECRET');

        Http::assertNothingSent();
    });
});

describe('which provider the container hands out', function (): void {
    it('is Stripe when PAYMENT_DRIVER says so', function (): void {
        expect(app(PaymentLinkProvider::class))->toBeInstanceOf(StripePaymentLinkProvider::class);
    });

    it('is the fake one by default, so a fresh clone works with no account', function (): void {
        config(['restaurantline.payments.driver' => 'fake']);

        expect(app(PaymentLinkProvider::class))->toBeInstanceOf(FakePaymentLinkProvider::class);
    });

    it('refuses to boot on a driver nobody implemented', function (): void {
        config(['restaurantline.payments.driver' => 'monopoly-money']);

        expect(fn () => app(PaymentLinkProvider::class))
            ->toThrow(InvalidArgumentException::class, 'monopoly-money');
    });
});
