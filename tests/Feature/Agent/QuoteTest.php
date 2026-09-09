<?php

declare(strict_types=1);

use Tests\Support\DemoMenu;

/**
 * `/quote` is half of the read-back constraint: a caller hears the itemised
 * total, composed from priced data, before an order exists. The other half is
 * `/orders` refusing to create anything the caller has not agreed to.
 *
 * The arithmetic itself belongs to PricingServiceTest. What is pinned here is
 * that the endpoint resolves the same slugs `/orders` will, prices the same
 * cart, and hands back a sentence rather than a set of integers for a language
 * model to add up.
 */
beforeEach(function (): void {
    $this->restaurant = alwaysOpen(restaurant());
    $this->menu = new DemoMenu($this->restaurant);
});

it('prices a collection order and reads it back as a sentence', function (): void {
    agentPost('quote', [
        'conversation_id' => 'call-1',
        'fulfilment' => 'collection',
        'items' => [['item' => 'ember-chicken-burger', 'quantity' => 2]],
    ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('subtotal', 1700)
        ->assertJsonPath('delivery_fee', 0)
        ->assertJsonPath('total', 1700)
        ->assertJsonPath('total_spoken', '17 pounds')
        ->assertJsonPath('currency', 'GBP')
        ->assertJsonPath('say', '2 Ember Chicken Burger, 17 pounds. That comes to 17 pounds.');
});

it('adds the price of each modifier to the line it belongs to', function (): void {
    $response = agentPost('quote', [
        'conversation_id' => 'call-1',
        'fulfilment' => 'collection',
        'items' => [[
            'item' => 'ember-chicken-burger',
            'quantity' => 1,
            'modifiers' => [
                ['modifier' => 'extra-cheese'],
                ['modifier' => 'gluten-free-bun'],
                ['modifier' => 'onions'],
            ],
        ], [
            'item' => 'chips',
        ]],
    ])->assertOk();

    // 850 + 100 cheese + 50 gluten-free bun + 0 removal, then 350 for chips.
    expect($response->json('items.0.line_total'))->toBe(1000)
        ->and($response->json('total'))->toBe(1350)
        // Each modifier already carries its own phrasing: a swap says "instead",
        // a removal says "no", and a free one carries no price at all. The agent
        // reads these out verbatim rather than composing them.
        ->and($response->json('items.0.modifiers'))
        ->toBe(['Extra Cheese, 1 pound', 'Gluten-Free Bun instead, 50 pence', 'no onions']);
});

it('bands the delivery fee on the distance sealed into the address token', function (): void {
    agentPost('quote', [
        'conversation_id' => 'call-1',
        'fulfilment' => 'delivery',
        'address_token' => addressToken(),
        'items' => [['item' => 'ember-chicken-burger', 'quantity' => 2]],
    ])
        ->assertJsonPath('delivery_fee', 199)
        ->assertJsonPath('delivery_fee_spoken', '1 pound 99')
        ->assertJsonPath('total', 1899)
        ->assertJsonPath('total_spoken', '18 pounds 99');
});

it('falls back to the catch-all band for a distant address', function (): void {
    agentPost('quote', [
        'conversation_id' => 'call-1',
        'fulfilment' => 'delivery',
        'address_token' => addressToken('14 High Street, Croydon, CR0 1QG'),
        'items' => [['item' => 'ember-chicken-burger', 'quantity' => 2]],
    ])->assertJsonPath('delivery_fee', 349);
});

/**
 * A caller may reasonably ask "how much would that come to?" before they have
 * given an address, so the quote is allowed to price without one.
 */
it('quotes a delivery without an address, using the flat fee', function (): void {
    agentPost('quote', [
        'conversation_id' => 'call-1',
        'fulfilment' => 'delivery',
        'items' => [['item' => 'ember-chicken-burger', 'quantity' => 2]],
    ])
        ->assertJsonPath('ok', true)
        // The restaurant's flat fee, not the catch-all distance band — with no
        // distance there is nothing to band on, and quietly charging the
        // longest band would overquote every caller who asks the price first.
        ->assertJsonPath('delivery_fee', 299);
});

it('asks for the address again rather than mispricing when the token is tampered with', function (): void {
    // A byte flipped inside the ciphertext, not a character appended: Laravel's
    // payload is base64, and a decoder will happily ignore a trailing byte. The
    // seal has to fail on a changed distance, which is the tamper that matters
    // — it is the one that makes delivery cheaper.
    $token = addressToken();
    $token = substr_replace($token, $token[40] === 'a' ? 'b' : 'a', 40, 1);

    agentPost('quote', [
        'conversation_id' => 'call-1',
        'fulfilment' => 'delivery',
        'address_token' => $token,
        'items' => [['item' => 'ember-chicken-burger']],
    ])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.say', "I've lost track of that address, sorry. Could you give it to me again?");
});

/**
 * Below the minimum is not a refusal. The useful sentence names the shortfall
 * and invites the caller to fix it, which is worth real money over a week of
 * calls.
 */
it('reports a shortfall as a suggestion rather than an error', function (): void {
    agentPost('quote', [
        'conversation_id' => 'call-1',
        'fulfilment' => 'delivery',
        'address_token' => addressToken(),
        'items' => [['item' => 'chips']],
    ])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'below_minimum')
        ->assertJsonPath('meets_minimum', false)
        ->assertJsonPath('shortfall_spoken', '11 pounds 50')
        ->assertJsonPath('error.say', "That comes to 5 pounds 49, and our minimum for delivery is 15 pounds — you're 11 pounds 50 short. Would you like to add anything?");
});

it('applies no minimum to a collection order', function (): void {
    agentPost('quote', [
        'conversation_id' => 'call-1',
        'fulfilment' => 'collection',
        'items' => [['item' => 'chips']],
    ])->assertJsonPath('ok', true)->assertJsonPath('meets_minimum', true);
});

it('suggests the right item when the agent guesses a slug', function (): void {
    $response = agentPost('quote', [
        'conversation_id' => 'call-1',
        'fulfilment' => 'collection',
        'items' => [['item' => 'chicken-burger']],
    ])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'item_not_found');

    expect($response->json('error.say'))->toContain('Did you mean Ember Chicken Burger')
        ->and($response->json('suggestions.0.item'))->toBe('ember-chicken-burger');
});

it('refuses to price a sold-out item', function (): void {
    agentPost('quote', [
        'conversation_id' => 'call-1',
        'fulfilment' => 'collection',
        'items' => [['item' => 'buttermilk-wings']],
    ])
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'item_unavailable')
        ->assertJsonPath('error.say', "I'm sorry, the Buttermilk Wings is sold out.");
});

it('refuses a modifier the item does not have', function (): void {
    agentPost('quote', [
        'conversation_id' => 'call-1',
        'fulfilment' => 'collection',
        'items' => [['item' => 'chips', 'modifiers' => [['modifier' => 'extra-cheese']]]],
    ])
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'modifier_not_found')
        ->assertJsonPath('error.say', "I can't do that with the Chips.");
});

it('refuses a modifier that has run out', function (): void {
    agentPost('quote', [
        'conversation_id' => 'call-1',
        'fulfilment' => 'collection',
        'items' => [['item' => 'ember-chicken-burger', 'modifiers' => [['modifier' => 'smoked-bacon']]]],
    ])
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'modifier_unavailable')
        ->assertJsonPath('error.say', "I'm sorry, we're out of smoked bacon.");
});

it('rejects a quote with no items at all', function (): void {
    agentPost('quote', ['conversation_id' => 'call-1', 'fulfilment' => 'collection', 'items' => []])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_request');
});

it('refuses item notes carrying a card number', function (): void {
    agentPost('quote', [
        'conversation_id' => 'call-1',
        'fulfilment' => 'collection',
        'items' => [['item' => 'ember-chicken-burger', 'notes' => 'pay with 4111 1111 1111 1111']],
    ])->assertStatus(422);
});
