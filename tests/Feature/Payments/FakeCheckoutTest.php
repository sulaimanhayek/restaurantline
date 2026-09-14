<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Payments\FakePaymentLinkProvider;
use App\Services\Payments\PaymentLinkException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * The page the fake driver's links point at.
 *
 * It exists so a forker can walk the whole payment path on a laptop with no
 * Stripe account: confirm an order, open the link from the SMS list, press the
 * button, watch the order go green. That is the first hour, and a dead link in
 * the middle of it is where somebody concludes the repo is half-finished.
 *
 * It is also a "mark this order paid" button with no authentication in front of
 * it, which is why most of what is tested here is the ways it refuses.
 */
beforeEach(function (): void {
    $this->restaurant = restaurant();
});

/**
 * An order carrying a fake payment reference, as the fake driver leaves it.
 *
 * @param  array<string, mixed>  $attributes
 */
function payableOrder(array $attributes = []): Order
{
    /** @var Order $order */
    $order = Order::factory()
        ->for(test()->restaurant)
        ->for(Customer::factory()->for(test()->restaurant))
        ->confirmed()
        ->create(array_replace([
            'payment_method' => PaymentMethod::CardLink,
            'payment_status' => PaymentStatus::LinkSent,
            'payment_reference' => 'fake_abcdef0123456789abcdef01',
        ], $attributes));

    return $order;
}

describe('the payment page', function (): void {
    it('shows the order total and a button to pay it', function (): void {
        $order = payableOrder();

        $this->get(route('payments.fake', ['reference' => $order->payment_reference]))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee($order->totalMoney()->format())
            ->assertSee('Test payment page.');
    });

    /*
     * A card field on this page would make it a plausible-looking place to type
     * a card number, which is the one thing this whole subsystem exists to
     * prevent. The real driver sends the customer to Stripe's own page.
     */
    it('asks for no card details of any kind', function (): void {
        $body = (string) $this->get(route('payments.fake', ['reference' => payableOrder()->payment_reference]))
            ->getContent();

        expect($body)->not->toContain('type="password"')
            ->and(strtolower($body))->not->toContain('card number')
            ->and(strtolower($body))->not->toContain('cvc');
    });

    it('marks the order paid when the button is pressed', function (): void {
        $order = payableOrder();

        $this->post(route('payments.fake.pay', ['reference' => $order->payment_reference]))
            ->assertRedirect(route('payments.return', ['order' => $order->order_number]));

        $order->refresh();

        expect($order->payment_status)->toBe(PaymentStatus::Paid)
            ->and($order->paid_at)->not->toBeNull()
            ->and($order->amount_paid)->toBe($order->total);
    });

    it('keeps the first paid_at when the button is pressed twice', function (): void {
        $order = payableOrder();

        $this->post(route('payments.fake.pay', ['reference' => $order->payment_reference]));
        $paidAt = $order->refresh()->paid_at;

        $this->travel(5)->minutes();
        $this->post(route('payments.fake.pay', ['reference' => $order->payment_reference]));

        expect($order->refresh()->paid_at?->equalTo($paidAt))->toBeTrue();
    });

    it('says so rather than offering the button again once paid', function (): void {
        $order = payableOrder(['payment_status' => PaymentStatus::Paid]);

        $this->get(route('payments.fake', ['reference' => $order->payment_reference]))
            ->assertOk()
            ->assertSee('already been paid');
    });
});

describe('the ways it refuses', function (): void {
    it('404s a reference that belongs to nothing', function (): void {
        payableOrder();

        $this->get(route('payments.fake', ['reference' => 'fake_nothing_here']))->assertNotFound();
        $this->post(route('payments.fake.pay', ['reference' => 'fake_nothing_here']))->assertNotFound();
    });

    /*
     * An install that has switched to Stripe still has old `fake_…` references
     * in its orders table. Those links must stop working the moment real money
     * is involved, which is why the guard is on the driver rather than only on
     * the reference.
     */
    it('404s once the install has switched to Stripe', function (): void {
        $order = payableOrder();
        config(['restaurantline.payments.driver' => 'stripe']);

        $this->get(route('payments.fake', ['reference' => $order->payment_reference]))->assertNotFound();
        $this->post(route('payments.fake.pay', ['reference' => $order->payment_reference]))->assertNotFound();

        expect($order->refresh()->payment_status)->toBe(PaymentStatus::LinkSent);
    });

    it('404s a real Stripe reference that happens to be on an order', function (): void {
        $order = payableOrder(['payment_reference' => 'cs_test_abc123']);

        $this->get(route('payments.fake', ['reference' => 'cs_test_abc123']))->assertNotFound();

        expect($order->refresh()->payment_status)->toBe(PaymentStatus::LinkSent);
    });
});

describe('the page the customer lands on afterwards', function (): void {
    it('thanks them without needing the order to exist', function (): void {
        $this->get(route('payments.return', ['order' => 'ANY-1234']))
            ->assertOk()
            ->assertSee('Thank you');
    });

    /*
     * An order number is short and guessable and this page has no
     * authentication, so it must not become a way to read somebody else's
     * dinner back to them. It says thank you and nothing else.
     */
    it('reveals nothing about the order in the URL', function (): void {
        $order = payableOrder();

        $this->get(route('payments.return', ['order' => $order->order_number]))
            ->assertOk()
            ->assertDontSee($order->totalMoney()->format())
            ->assertDontSee((string) $order->customer?->name);
    });
});

describe('the driver that hands out these links', function (): void {
    it('makes a link pointing at this application', function (): void {
        $link = (new FakePaymentLinkProvider)->createLink(payableOrder());

        expect($link->url)->toBe(url('/pay/'.$link->reference))
            ->and($link->reference)->toStartWith('fake_')
            ->and($link->expiresAt)->not->toBeNull();
    });

    it('honours a restaurant that wants links that never expire', function (): void {
        $this->restaurant->update(['payment_link_ttl_minutes' => 0]);

        expect((new FakePaymentLinkProvider)->createLink(payableOrder())->expiresAt)->toBeNull();
    });

    /*
     * A page anybody can reach and press "pay" on is a development convenience
     * right up until real orders go through it, and the failure — a month of
     * orders marked paid that nobody was paid for — is quiet enough to survive
     * a month. So it refuses rather than warns.
     */
    it('refuses to run in production at all', function (): void {
        $this->app->detectEnvironment(fn (): string => 'production');

        expect(fn () => (new FakePaymentLinkProvider)->createLink(payableOrder()))
            ->toThrow(PaymentLinkException::class, 'PAYMENT_DRIVER=stripe');
    });

    /*
     * The one honest reason to override it: a restaurant that takes no card
     * payments at all and only ever wanted the cash path.
     */
    it('can be allowed in production by somebody who means it', function (): void {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['restaurantline.payments.allow_fake_in_production' => true]);

        expect((new FakePaymentLinkProvider)->createLink(payableOrder())->reference)->toStartWith('fake_');
    });

    /*
     * And the page those links point at stays shut in production regardless,
     * because the override is about being able to create a reference, not about
     * exposing a button that marks orders paid.
     */
    it('leaves the page itself shut in production even then', function (): void {
        $order = payableOrder();
        config(['restaurantline.payments.allow_fake_in_production' => true]);
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->get(route('payments.fake', ['reference' => $order->payment_reference]))->assertNotFound();
        $this->post(route('payments.fake.pay', ['reference' => $order->payment_reference]))->assertNotFound();

        expect($order->refresh()->payment_status)->toBe(PaymentStatus::LinkSent);
        // CSRF is waived here only because pretending to be production inside
        // the suite switches Laravel's own test exemption off; a 419 would mask
        // the 404 this test is actually about.
    })->withoutMiddleware(ValidateCsrfToken::class);
});
