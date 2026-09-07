<?php

declare(strict_types=1);

use Tests\Support\DemoMenu;

/**
 * The two endpoints the agent leans on hardest. `/menu/search` runs on nearly
 * every turn of a call; `/menu` is what the agent falls back to when a caller
 * says "what do you do?".
 *
 * The matcher itself is covered in Services/MenuMatchingServiceTest. What
 * matters here is the contract: slugs rather than ids, a spoken price beside
 * every number, and a miss that answers 200 with `ok: false` rather than a 404
 * the agent has to interpret.
 */
beforeEach(function (): void {
    $this->restaurant = alwaysOpen(restaurant());
    $this->menu = new DemoMenu($this->restaurant);
});

it('finds an item said the way a caller says it', function (): void {
    agentPost('menu/search', [
        'conversation_id' => 'call-1',
        'query' => 'erm can I get a chicken burger please',
    ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('matches.0.item', 'ember-chicken-burger')
        ->assertJsonPath('matches.0.name', 'Ember Chicken Burger')
        ->assertJsonPath('matches.0.price', 850)
        ->assertJsonPath('matches.0.price_spoken', '8 pounds 50')
        ->assertJsonPath('matches.0.available', true);
});

it('refers to items by slug, never by database id', function (): void {
    $response = agentPost('menu/search', ['conversation_id' => 'call-1', 'query' => 'chips']);

    expect($response->json('matches.0'))->toHaveKey('item')
        ->and($response->json('matches.0'))->not->toHaveKey('id');
});

it('answers a miss with ok false rather than an error status', function (): void {
    agentPost('menu/search', ['conversation_id' => 'call-1', 'query' => 'sushi'])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'item_not_found')
        ->assertJsonPath('error.say', "I'm sorry, I don't think we do that. Would you like me to run through the menu?");
});

it('still returns a sold-out item, with a reason the agent can say', function (): void {
    $match = rows(agentPost('menu/search', [
        'conversation_id' => 'call-1',
        'query' => 'buttermilk wings',
    ])->json('matches'))->firstWhere('item', 'buttermilk-wings');

    expect($match)->not->toBeNull()
        ->and($match['available'])->toBeFalse()
        ->and($match['unavailable_reason'])->toBe('sold out');
});

it('marks an out-of-hours item unavailable with the window rather than sold out', function (): void {
    $this->travelTo($this->restaurant->now()->setTime(20, 0));

    $match = rows(agentPost('menu/search', [
        'conversation_id' => 'call-1',
        'query' => 'lunch wrap meal',
    ])->json('matches'))->firstWhere('item', 'lunch-wrap-meal');

    expect($match['available'])->toBeFalse()
        ->and($match['unavailable_reason'])->toBe('only served 11:30am to 3pm');
});

it('respects the requested result limit', function (): void {
    $response = agentPost('menu/search', ['conversation_id' => 'call-1', 'query' => 'burger', 'limit' => 1]);

    expect($response->json('matches'))->toHaveCount(1);
});

it('rejects a search with no query', function (): void {
    agentPost('menu/search', ['conversation_id' => 'call-1'])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'invalid_request');
});

it('returns the whole menu with modifier groups', function (): void {
    $response = agentGet('menu')->assertOk()->assertJsonPath('ok', true);

    expect($response->json('restaurant'))->toBe($this->restaurant->name)
        ->and($response->json('currency'))->toBe('GBP')
        ->and(rows($response->json('categories'))->pluck('category')->all())
        ->toBe(['burgers', 'lunch'])
        ->and(rows($response->json('modifier_groups'))->pluck('group')->all())
        ->toBe(['bun', 'extras']);
});

it('says which modifier group is required and how many may be chosen', function (): void {
    $groups = rows(agentGet('menu')->json('modifier_groups'))->keyBy('group');

    expect($groups['bun']['required'])->toBeTrue()
        ->and($groups['bun']['selection'])->toBe('single')
        ->and($groups['bun']['max'])->toBe(1)
        ->and($groups['extras']['required'])->toBeFalse()
        ->and($groups['extras']['selection'])->toBe('multi')
        ->and($groups['extras']['max'])->toBe(5);
});

it('gives a free modifier no spoken price, so the agent does not say "zero pounds"', function (): void {
    $modifiers = rows(agentGet('menu')->json('modifier_groups'))
        ->firstWhere('group', 'extras')['modifiers'];

    $cheese = rows($modifiers)->firstWhere('modifier', 'extra-cheese');
    $onions = rows($modifiers)->firstWhere('modifier', 'onions');

    expect($cheese['price_delta_spoken'])->toBe('1 pound')
        ->and($onions['price_delta'])->toBe(0)
        ->and($onions['price_delta_spoken'])->toBeNull();
});

it('keeps an unavailable modifier in the menu but flags it', function (): void {
    $bacon = rows(rows(agentGet('menu')->json('modifier_groups'))
        ->firstWhere('group', 'extras')['modifiers'])
        ->firstWhere('modifier', 'smoked-bacon');

    expect($bacon['available'])->toBeFalse();
});

it('filters the menu to one category', function (): void {
    $response = agentGet('menu', ['category' => 'lunch']);

    expect($response->json('categories'))->toHaveCount(1)
        ->and($response->json('categories.0.category'))->toBe('lunch');
});

it('says so when asked for a category that does not exist', function (): void {
    agentGet('menu', ['category' => 'sushi'])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'item_not_found')
        ->assertJsonPath('category', 'sushi');
});
