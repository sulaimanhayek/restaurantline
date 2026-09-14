<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Tests\Support\DemoMenu;

/**
 * Who decides how the caller pays.
 *
 * The rule, in one sentence: the restaurant declares what it accepts, and the
 * agent is only asked a question when there is genuinely more than one answer.
 * Everything below is a consequence of that.
 *
 * @see docs/DECISIONS.md #0037
 */
beforeEach(function (): void {
    $this->restaurant = alwaysOpen(restaurant());
    $this->menu = new DemoMenu($this->restaurant);
});

/**
 * Place an order and confirm it, the way the agent does.
 *
 * The placement is asserted rather than assumed: a setup step that quietly
 * fails hands every test below an empty order number and a 404 from a URL with
 * a hole in it, which says nothing about payment methods.
 *
 * @param  array<string, mixed>  $payload
 * @return TestResponse<Response>
 */
function confirmWith(array $payload = []): TestResponse
{
    $number = (string) agentPost('orders', orderPayload())
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->json('order_number');

    return agentPost("orders/{$number}/confirm", array_replace(['conversation_id' => 'call-1'], $payload));
}

describe('a restaurant that takes both', function (): void {
    it('will not confirm without an answer', function (): void {
        confirmWith()
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('errors.payment_method.0', 'The payment method field is required.');
    });

    it('takes the answer the caller gave', function (): void {
        confirmWith(['payment_method' => 'card_link'])
            ->assertOk()
            ->assertJsonPath('payment_method', 'card_link');

        expect(Order::query()->sole()->payment_method)->toBe(PaymentMethod::CardLink);
    });

    it('refuses a method that is not one of the two', function (): void {
        confirmWith(['payment_method' => 'card_over_the_phone'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_request');
    });
});

describe('a restaurant that takes one', function (): void {
    it('picks cash without being asked', function (): void {
        $this->restaurant->update(['accepts_card_link' => false]);

        confirmWith()
            ->assertOk()
            ->assertJsonPath('payment_method', 'cash')
            ->assertJsonPath('payment_status', 'cash_on_collection');
    });

    it('picks the card link without being asked', function (): void {
        $this->restaurant->update(['accepts_cash' => false]);

        confirmWith()
            ->assertOk()
            ->assertJsonPath('payment_method', 'card_link');
    });

    /*
     * The agent for a cash-only restaurant is never told a card link exists, so
     * a `card_link` arriving from one is a model being creative. Honouring it
     * would text a payment link on behalf of a business that has no card
     * processing set up — an outcome nobody in the conversation asked for.
     */
    it('ignores a method the restaurant does not offer', function (): void {
        $this->restaurant->update(['accepts_card_link' => false]);

        confirmWith(['payment_method' => 'card_link'])
            ->assertOk()
            ->assertJsonPath('payment_method', 'cash');
    });

    /*
     * A misconfiguration, not a supported setting. The order is real and the
     * caller is waiting, so it lands on cash rather than failing — somebody can
     * take money at the door, and nobody can take money from an order that was
     * never confirmed.
     */
    it('falls back to cash when the restaurant accepts neither', function (): void {
        $this->restaurant->update(['accepts_card_link' => false, 'accepts_cash' => false]);

        confirmWith()
            ->assertOk()
            ->assertJsonPath('payment_method', 'cash');
    });
});

describe('the state an order lands in', function (): void {
    it('treats cash as settled as far as this application is concerned', function (): void {
        confirmWith(['payment_method' => 'cash']);

        expect(Order::query()->sole()->payment_status)->toBe(PaymentStatus::CashOnCollection);
    });

    /*
     * `link_sent`, not `unpaid`, because the text went out — and it went out
     * during this request, since the queue runs synchronously in the suite.
     * The distinction matters on the kitchen ticket, which shows one of them as
     * money still to collect.
     */
    it('records a card order as having had its link sent', function (): void {
        confirmWith(['payment_method' => 'card_link']);

        $order = Order::query()->sole();

        expect($order->payment_status)->toBe(PaymentStatus::LinkSent)
            ->and($order->payment_link_url)->toStartWith(url('/pay/'))
            ->and($order->payment_reference)->toStartWith('fake_');
    });
});

/*
 * The rule this whole design exists to serve. There is no field on this
 * endpoint that takes a card number, and a payload carrying one must not reach
 * the database through a field that happens to accept a string.
 *
 * @see app/Services/Payments/README.md
 */
it('has nowhere to put a card number', function (): void {
    confirmWith(['payment_method' => 'cash', 'card_number' => '4242424242424242'])
        ->assertOk();

    $order = Order::query()->sole();

    expect($order->getAttributes())->not->toHaveKey('card_number')
        ->and(json_encode($order->getAttributes()))->not->toContain('4242');
});
