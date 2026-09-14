<?php

declare(strict_types=1);

use App\Enums\ConversationOutcome;
use App\Enums\OrderStatus;
use App\Models\Conversation;
use App\Models\Order;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Tests\Support\DemoMenu;

/**
 * The moment the caller's yes turns a basket into food.
 *
 * Everything that happens after this — the kitchen display, the confirmation
 * SMS, the payment link — hangs off `confirmed`, which is why nothing else in
 * the system is allowed to set it.
 */
beforeEach(function (): void {
    $this->restaurant = alwaysOpen(restaurant());
    $this->menu = new DemoMenu($this->restaurant);
});

/**
 * Take an order the way the agent does, and hand back its number.
 */
function placedOrderNumber(string $conversationId = 'call-1'): string
{
    return (string) agentPost('orders', orderPayload(['conversation_id' => $conversationId]))
        ->json('order_number');
}

/**
 * Confirm the way the agent does.
 *
 * The seeded restaurant takes both card and cash, so `payment_method` is a
 * required field here — see PaymentMethodTest for the rules about when it is
 * required, ignored or supplied by the application. Everything in this file is
 * about confirmation itself, so it passes the cheapest valid value and moves
 * on.
 *
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function agentConfirm(string $number, array $overrides = []): TestResponse
{
    return agentPost("orders/{$number}/confirm", array_replace([
        'conversation_id' => 'call-1',
        'payment_method' => 'cash',
    ], $overrides));
}

it('confirms an order the caller agreed to', function (): void {
    $number = placedOrderNumber();

    $response = agentConfirm($number)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('order_number', $number);

    $order = Order::query()->sole();

    expect($order->status)->toBe(OrderStatus::Confirmed)
        ->and($order->confirmed_at)->not->toBeNull()
        ->and($response->json('confirmed_at'))->not->toBeNull()
        ->and($response->json('say'))
        ->toMatch("/^Lovely, that's confirmed\. Your order number is .+ and it'll be ready in about 20 minutes\. That's cash when you collect\.$/");
});

/**
 * A model handed "L-2356" reads it as a word. Spacing the characters out is
 * what makes the agent say "L, two, three, five, six" — the caller is going to
 * repeat this number at a counter.
 */
it('reads the order number back character by character', function (): void {
    $number = placedOrderNumber();

    expect(agentConfirm($number)->json('order_number_spoken'))
        ->toBe(trim(implode(' ', str_split(str_replace('-', ' ', $number)))))
        ->not->toBe($number);
});

it('records the call as having produced an order', function (): void {
    $number = placedOrderNumber();

    agentConfirm($number);

    expect(Conversation::query()->sole()->outcome)->toBe(ConversationOutcome::OrderPlaced);
});

/**
 * A retried confirm is a success. ElevenLabs retries a tool call it did not
 * hear back from, and a caller must never be told something went wrong with an
 * order that is perfectly fine.
 */
it('treats a retried confirmation as a success, not a conflict', function (): void {
    $number = placedOrderNumber();

    agentConfirm($number);
    $confirmedAt = Order::query()->sole()->confirmed_at;

    agentConfirm($number)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('idempotent_replay', true);

    // The second call must not move the clock on an order the kitchen may
    // already be timing.
    expect(Order::query()->sole()->confirmed_at->equalTo($confirmedAt))->toBeTrue();
});

it('will not confirm an order that has moved on to the kitchen', function (): void {
    $number = placedOrderNumber();
    Order::query()->sole()->update(['status' => OrderStatus::Preparing]);

    agentConfirm($number)
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'order_not_confirmable')
        ->assertJsonPath('error.say', 'That order is already with the kitchen.')
        ->assertJsonPath('status', 'preparing');
});

it('says so plainly when the order was cancelled', function (): void {
    $number = placedOrderNumber();
    Order::query()->sole()->update(['status' => OrderStatus::Cancelled]);

    agentConfirm($number)
        ->assertJsonPath('error.code', 'order_not_confirmable')
        ->assertJsonPath('error.say', "That order has already been closed off, I'm afraid.");
});

it('offers to start again when the number does not exist', function (): void {
    agentConfirm('NOPE-99')
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'order_not_found')
        ->assertJsonPath('error.say', "I can't find that order, sorry. Let me start it again.");
});

/**
 * Orders are addressed by the number the caller was read, and that number is
 * scoped to the restaurant. A confirm must never reach across tenants — the
 * schema is built for multi-tenancy from day one and this is where a missing
 * scope would first do damage.
 */
it('cannot confirm an order belonging to another restaurant', function (): void {
    $number = placedOrderNumber();

    $other = restaurant(['name' => 'Somewhere Else']);
    Order::query()->sole()->update(['restaurant_id' => $other->id]);

    agentConfirm($number)
        ->assertJsonPath('error.code', 'order_not_found');
});

it('requires the conversation it belongs to', function (): void {
    $number = placedOrderNumber();

    agentPost("orders/{$number}/confirm", ['payment_method' => 'cash'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_request');
});
