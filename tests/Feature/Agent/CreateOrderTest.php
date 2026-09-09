<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\OpeningHour;
use App\Models\Order;
use Tests\Support\DemoMenu;

/**
 * The endpoint where a conversation turns into a row somebody cooks from.
 *
 * Three of the project's stated constraints are enforced here rather than in a
 * prompt, and each has a test that fails loudly if it is relaxed: an order is
 * created in `confirming` and never in `confirmed`; a delivery address must
 * have been sealed by the geocoder and confirmed aloud; and no field on the way
 * in may carry a card number.
 */
beforeEach(function (): void {
    $this->restaurant = alwaysOpen(restaurant());
    $this->menu = new DemoMenu($this->restaurant);
});

it('creates a collection order and hands back the number the caller heard', function (): void {
    $response = agentPost('orders', orderPayload())
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('status', 'confirming')
        ->assertJsonPath('fulfilment', 'collection')
        ->assertJsonPath('total', 1700)
        ->assertJsonPath('total_spoken', '17 pounds')
        ->assertJsonPath('requires_confirmation', true);

    $order = Order::query()->sole();

    expect($response->json('order_number'))->toBe($order->order_number)
        ->and($order->restaurant_id)->toBe($this->restaurant->id)
        ->and($order->source->value)->toBe('voice');
});

/**
 * The load-bearing half of "always read the order back before creating it". If
 * this ever comes back `confirmed`, food goes onto the pass for a caller who
 * has not said yes.
 */
it('never creates an order already confirmed', function (): void {
    agentPost('orders', orderPayload());

    $order = Order::query()->sole();

    expect($order->status)->toBe(OrderStatus::Confirming)
        ->and($order->confirmed_at)->toBeNull();
});

it('snapshots the item rather than pointing at the live menu row', function (): void {
    agentPost('orders', orderPayload([
        'items' => [[
            'item' => 'ember-chicken-burger',
            'modifiers' => [['modifier' => 'extra-cheese'], ['modifier' => 'onions']],
        ]],
    ]));

    $item = Order::query()->sole()->items()->sole();

    // Renaming and repricing the menu afterwards must not disturb the order.
    $this->menu->emberBurger->update(['name' => 'Renamed', 'price' => 9999]);

    expect($item->refresh()->name)->toBe('Ember Chicken Burger')
        ->and($item->unit_price)->toBe(950)
        ->and($item->modifiers->pluck('name')->all())->toBe(['Extra Cheese', 'Onions'])
        ->and($item->modifiers_snapshot)->not->toBeEmpty();
});

it('quotes the wait as minutes while the kitchen is open', function (): void {
    $response = agentPost('orders', orderPayload());

    expect($response->json('say'))->toMatch('/^That\'s all in\. Your order number is .+, and it\'ll be ready in about 20 minutes\.$/');
});

/**
 * Found by running the thing: an order taken while the kitchen was shut rolled
 * silently to the next opening and the agent read the wait out as "about 1026
 * minutes". `/availability` had the check; this endpoint did not, and it is the
 * one that must not depend on the agent having called the other first.
 */
it('refuses an order when the kitchen is closed, rather than booking it for tomorrow', function (): void {
    OpeningHour::query()->where('restaurant_id', $this->restaurant->id)->delete();

    foreach (range(0, 6) as $dayOfWeek) {
        OpeningHour::factory()->for($this->restaurant)->dinner()->forDay($dayOfWeek)->create();
    }

    $this->travelTo($this->restaurant->now()->setTime(3, 0));

    agentPost('orders', orderPayload())
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'closed')
        ->assertJsonPath('error.say', "I'm sorry, we're closed at the moment. We open again later today at 5pm.")
        ->assertJsonPath('next_opens_spoken', 'later today at 5pm');

    expect(Order::query()->count())->toBe(0);
});

/**
 * The other half of the same live bug. An hours check stops the absurd case,
 * but a long prep time on a legitimately open kitchen still produces a number
 * nobody says out loud — so past a couple of hours the wait becomes a clock
 * time.
 */
it('reads a very long wait as a time rather than a count of minutes', function (): void {
    $this->restaurant->update(['collection_prep_minutes' => 180]);
    $this->travelTo($this->restaurant->now()->setTime(13, 0));

    expect(agentPost('orders', orderPayload())->json('say'))
        ->toContain("it'll be ready later today, around 4pm.");
});

it('refuses to take an order while the kitchen has paused', function (): void {
    $this->restaurant->update(['is_accepting_orders' => false]);

    agentPost('orders', orderPayload())
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'not_accepting_orders');

    expect(Order::query()->count())->toBe(0);
});

it('takes a delivery order against a sealed, confirmed address', function (): void {
    $token = addressToken();

    $response = agentPost('orders', orderPayload([
        'fulfilment' => 'delivery',
        'address_token' => $token,
        'address_confirmed' => true,
    ]))->assertOk()->assertJsonPath('ok', true);

    $address = Address::query()->sole();

    expect($response->json('delivery_fee'))->toBe(199)
        ->and($response->json('total'))->toBe(1899)
        ->and($address->verified_at)->not->toBeNull()
        ->and($address->raw_spoken_text)->toBe('3 Hanbury Street, E1 6QR')
        ->and($address->postcode)->toBe('E1 6QR')
        ->and(Order::query()->sole()->address_id)->toBe($address->id);
});

/**
 * "Address must be confirmed aloud" enforced as a rule rather than a hope. The
 * agent asserting it is the only evidence available, so the absence of that
 * assertion has to stop the order.
 */
it('refuses a delivery whose address was never read back', function (): void {
    agentPost('orders', orderPayload([
        'fulfilment' => 'delivery',
        'address_token' => addressToken(),
        'address_confirmed' => false,
    ]))
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'address_not_confirmed')
        ->assertJsonPath('error.say', 'Let me just check the address with you before I put this through.');

    expect(Order::query()->count())->toBe(0)
        ->and(Address::query()->count())->toBe(0);
});

it('will not take a delivery order with no address at all', function (): void {
    agentPost('orders', orderPayload(['fulfilment' => 'delivery']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_request');
});

/**
 * The seal is what stops a model inventing an address, or editing the distance
 * on one it was given to make delivery cheaper.
 */
it('refuses an address token that has been tampered with', function (): void {
    $token = addressToken();
    $token = substr_replace($token, $token[40] === 'a' ? 'b' : 'a', 40, 1);

    agentPost('orders', orderPayload([
        'fulfilment' => 'delivery',
        'address_token' => $token,
        'address_confirmed' => true,
    ]))->assertJsonPath('ok', false);

    expect(Order::query()->count())->toBe(0);
});

it('refuses to deliver outside the radius even with a valid token', function (): void {
    agentPost('orders', orderPayload([
        'fulfilment' => 'delivery',
        'address_token' => addressToken('14 High Street, Croydon, CR0 1QG'),
        'address_confirmed' => true,
    ]))
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'outside_delivery_area');

    expect(Order::query()->count())->toBe(0);
});

/**
 * ElevenLabs retries a tool call it does not hear back from. A retry has to
 * return the order that exists — not cook the food twice, and not burn a second
 * order number the caller was never told.
 */
it('returns the existing order when the tool call is retried', function (): void {
    $first = agentPost('orders', orderPayload())->json('order_number');

    $replay = agentPost('orders', orderPayload())
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('idempotent_replay', true);

    expect($replay->json('order_number'))->toBe($first)
        ->and(Order::query()->count())->toBe(1)
        ->and(Customer::query()->count())->toBe(1);
});

it('replays the existing order even after the kitchen stops accepting', function (): void {
    agentPost('orders', orderPayload());
    $this->restaurant->update(['is_accepting_orders' => false]);

    agentPost('orders', orderPayload())
        ->assertJsonPath('ok', true)
        ->assertJsonPath('idempotent_replay', true);
});

it('says "free" rather than "0 pounds" when there is no delivery fee', function (): void {
    expect(agentPost('orders', orderPayload())->json('delivery_fee_spoken'))->toBe('free');
});

it('reuses a returning caller rather than duplicating them', function (): void {
    $existing = Customer::factory()->for($this->restaurant)->create([
        'phone_number' => '+447700900123',
        'name' => 'Sam',
    ]);

    agentPost('orders', orderPayload());

    expect(Customer::query()->count())->toBe(1)
        ->and(Order::query()->sole()->customer_id)->toBe($existing->id);
});

/**
 * A call where the agent did not catch the name must not wipe the name from the
 * two calls that did.
 */
it('never clears a known name because one call did not catch it', function (): void {
    agentPost('orders', orderPayload());

    agentPost('orders', orderPayload([
        'conversation_id' => 'call-2',
        'customer' => ['phone_number' => '+447700900123'],
    ]));

    expect(Customer::query()->sole()->name)->toBe('Sam');
});

it('starts a conversation row for the call and attaches the order to it', function (): void {
    agentPost('orders', orderPayload());

    $conversation = Conversation::query()->sole();

    expect($conversation->elevenlabs_conversation_id)->toBe('call-1')
        ->and($conversation->caller_number)->toBe('+447700900123')
        ->and(Order::query()->sole()->conversation_id)->toBe($conversation->id);
});

it('refuses an order below the delivery minimum', function (): void {
    agentPost('orders', orderPayload([
        'fulfilment' => 'delivery',
        'address_token' => addressToken(),
        'address_confirmed' => true,
        'items' => [['item' => 'chips']],
    ]))
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'below_minimum')
        ->assertJsonPath('error.say', "That's 11 pounds 50 short of our 15 pounds minimum. Would you like to add anything?");

    expect(Order::query()->count())->toBe(0);
});

it('requires a phone number to reach the caller on', function (): void {
    agentPost('orders', orderPayload(['customer' => ['name' => 'Sam']]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_request');
});

/**
 * The constraint stated in the README, enforced at the only place a card number
 * could plausibly arrive: free text. Payment is an SMS link after the call.
 */
it('refuses order notes containing a card number', function (): void {
    agentPost('orders', orderPayload(['notes' => 'card 4539 1488 0343 6467, expiry 03/29']))
        ->assertStatus(422);

    expect(Order::query()->count())->toBe(0);
});

it('refuses a customer name containing a card number', function (): void {
    agentPost('orders', orderPayload([
        'customer' => ['phone_number' => '+447700900123', 'name' => '4111111111111111'],
    ]))->assertStatus(422);

    expect(Customer::query()->count())->toBe(0);
});

it('lets an ordinary phone number through', function (): void {
    agentPost('orders', orderPayload(['notes' => 'buzzer 42, call 07700 900123 on arrival']))
        ->assertOk()
        ->assertJsonPath('ok', true);
});

/**
 * The rule slides a 13-to-19 digit window across the whole digit run, so a long
 * enough string of digits will contain a Luhn-valid window whatever it actually
 * means. That is the deliberate direction to be wrong in: the cost of refusing
 * an unusual note is a caller repeating themselves, and the cost of the other
 * mistake is a card number in a transcript, a webhook payload and a recording.
 */
it('errs towards refusing a long run of digits that is not a card', function (): void {
    agentPost('orders', orderPayload(['notes' => 'gate code 1234 5678 9012 3456']))
        ->assertStatus(422);
});
