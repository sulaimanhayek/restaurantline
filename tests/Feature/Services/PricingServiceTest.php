<?php

declare(strict_types=1);

use App\Enums\FulfilmentType;
use App\Models\DeliveryFeeRule;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Services\Pricing\Cart;
use App\Services\Pricing\CartLine;
use App\Services\Pricing\CartModifier;
use App\Services\Pricing\PricingService;

/**
 * A wrong total is the one bug a caller notices, an operator disputes and
 * nobody can reconstruct afterwards. Every rule that moves a number is covered
 * here, in minor units throughout — see DECISIONS #0001.
 */
beforeEach(function (): void {
    $this->restaurant = restaurant([
        'currency' => 'GBP',
        'minimum_order_value' => 1500,
        'base_delivery_fee' => 299,
        'delivery_radius_metres' => 5000,
    ]);

    $this->pricing = app(PricingService::class);

    $this->extras = ModifierGroup::factory()
        ->for($this->restaurant)
        ->multi(max: 5)
        ->create(['name' => 'Extras', 'slug' => 'extras']);

    $this->cheese = Modifier::factory()
        ->for($this->restaurant)
        ->for($this->extras, 'group')
        ->addon(priceDelta: 100)
        ->create(['name' => 'Extra Cheese', 'slug' => 'extra-cheese']);

    $this->onions = Modifier::factory()
        ->for($this->restaurant)
        ->for($this->extras, 'group')
        ->removal()
        ->create(['name' => 'Onions', 'slug' => 'onions']);

    $this->burger = MenuItem::factory()
        ->for($this->restaurant)
        ->pricedAt(850)
        ->create(['name' => 'Ember Chicken Burger']);

    $this->burger->modifierGroups()->attach($this->extras);
});

/**
 * @param  list<CartLine>  $lines
 */
function cart(FulfilmentType $fulfilment, array $lines, ?int $distance = null): Cart
{
    return new Cart(
        restaurant: test()->restaurant,
        fulfilment: $fulfilment,
        lines: $lines,
        distanceMetres: $distance,
    );
}

it('multiplies the item and its modifiers by the line quantity', function (): void {
    $priced = $this->pricing->price(cart(FulfilmentType::Collection, [
        new CartLine($this->burger, quantity: 2, modifiers: [new CartModifier($this->cheese)]),
    ]));

    // (850 + 100) × 2
    expect($priced->subtotal)->toBe(1900)
        ->and($priced->lines[0]->unitPrice)->toBe(850)
        ->and($priced->lines[0]->modifiersTotal)->toBe(100)
        ->and($priced->lines[0]->lineTotal)->toBe(1900)
        ->and($priced->total)->toBe(1900);
});

it('charges a double modifier twice', function (): void {
    $priced = $this->pricing->price(cart(FulfilmentType::Collection, [
        new CartLine($this->burger, modifiers: [new CartModifier($this->cheese, quantity: 2)]),
    ]));

    expect($priced->lines[0]->modifiersTotal)->toBe(200)
        ->and($priced->subtotal)->toBe(1050);
});

it('will not leave the onions out twice', function (): void {
    $priced = $this->pricing->price(cart(FulfilmentType::Collection, [
        new CartLine($this->burger, modifiers: [new CartModifier($this->onions, quantity: 3)]),
    ]));

    expect($priced->lines[0]->modifiers[0]->quantity)->toBe(1)
        ->and($priced->lines[0]->modifiers[0]->spoken())->toBe('no onions')
        ->and($priced->subtotal)->toBe(850);
});

it('prefers the item\'s own price for a shared modifier', function (): void {
    // Extra cheese costs 100 everywhere, except on this burger.
    $this->burger->modifierOverrides()->attach($this->cheese->id, ['price_delta_override' => 175]);

    $priced = $this->pricing->price(cart(FulfilmentType::Collection, [
        new CartLine($this->burger->fresh(), modifiers: [new CartModifier($this->cheese)]),
    ]));

    expect($priced->lines[0]->modifiersTotal)->toBe(175)
        ->and($priced->subtotal)->toBe(1025);
});

it('refuses to price a line with no quantity rather than guessing at one', function (): void {
    expect(fn (): mixed => $this->pricing->priceLine(new CartLine($this->burger, quantity: 0), 'GBP'))
        ->toThrow(InvalidArgumentException::class, 'Ember Chicken Burger');
});

it('charges no delivery fee on a collection order', function (): void {
    DeliveryFeeRule::factory()->for($this->restaurant)->band(upToMetres: null, fee: 499)->create();

    $priced = $this->pricing->price(cart(FulfilmentType::Collection, [
        new CartLine($this->burger),
    ], distance: 4000));

    expect($priced->deliveryFee)->toBe(0)
        ->and($priced->total)->toBe(850);
});

it('exempts collection from the minimum order value', function (): void {
    $priced = $this->pricing->price(cart(FulfilmentType::Collection, [new CartLine($this->burger)]));

    expect($priced->minimumOrderValue)->toBe(0)
        ->and($priced->meetsMinimum)->toBeTrue()
        ->and($priced->shortfall)->toBe(0);
});

it('applies the minimum to collection when the operator asks for it', function (): void {
    config()->set('restaurantline.pricing.minimum_applies_to_collection', true);

    $priced = $this->pricing->price(cart(FulfilmentType::Collection, [new CartLine($this->burger)]));

    expect($priced->minimumOrderValue)->toBe(1500)
        ->and($priced->meetsMinimum)->toBeFalse()
        ->and($priced->shortfall)->toBe(650);
});

it('reports the shortfall on a delivery order under the minimum', function (): void {
    $priced = $this->pricing->price(cart(FulfilmentType::Delivery, [new CartLine($this->burger)], distance: 1000));

    expect($priced->meetsMinimum)->toBeFalse()
        ->and($priced->shortfall)->toBe(650)
        ->and($priced->shortfallMoney()->spoken())->toBe('6 pounds 50');
});

describe('delivery fee bands', function (): void {
    beforeEach(function (): void {
        DeliveryFeeRule::factory()->for($this->restaurant)->band(upToMetres: 2000, fee: 199, sortOrder: 0)->create();
        DeliveryFeeRule::factory()->for($this->restaurant)->band(upToMetres: 4000, fee: 349, sortOrder: 1)->create();
        DeliveryFeeRule::factory()->for($this->restaurant)->band(upToMetres: null, fee: 549, freeOver: 3000, sortOrder: 2)->create();
    });

    it('picks the first band covering the distance', function (int $distance, int $fee): void {
        $priced = $this->pricing->price(cart(FulfilmentType::Delivery, [
            new CartLine($this->burger, quantity: 2),
        ], distance: $distance));

        expect($priced->deliveryFee)->toBe($fee)
            ->and($priced->deliveryFeeWaived)->toBeFalse();
    })->with([
        'inside the first band' => [500, 199],
        'on the boundary' => [2000, 199],
        'one metre over it' => [2001, 349],
        'the catch-all' => [9000, 549],
    ]);

    it('falls back to the flat fee when the address has not been resolved yet', function (): void {
        $priced = $this->pricing->price(cart(FulfilmentType::Delivery, [
            new CartLine($this->burger, quantity: 2),
        ], distance: null));

        expect($priced->deliveryFee)->toBe(299);
    });

    it('waives the fee once the free-delivery threshold is met', function (): void {
        // Four burgers is 3400, over the catch-all band's 3000 threshold.
        $priced = $this->pricing->price(cart(FulfilmentType::Delivery, [
            new CartLine($this->burger, quantity: 4),
        ], distance: 9000));

        expect($priced->subtotal)->toBe(3400)
            ->and($priced->deliveryFee)->toBe(0)
            ->and($priced->deliveryFeeWaived)->toBeTrue()
            ->and($priced->total)->toBe(3400);
    });

    it('leaves a nearer order paying its band even when it is over the threshold', function (): void {
        // The threshold belongs to the band, not to the restaurant.
        $priced = $this->pricing->price(cart(FulfilmentType::Delivery, [
            new CartLine($this->burger, quantity: 4),
        ], distance: 500));

        expect($priced->deliveryFee)->toBe(199)
            ->and($priced->deliveryFeeWaived)->toBeFalse();
    });
});

it('falls back to the restaurant\'s flat fee when no bands are configured', function (): void {
    $priced = $this->pricing->price(cart(FulfilmentType::Delivery, [
        new CartLine($this->burger, quantity: 2),
    ], distance: 3000));

    expect($priced->deliveryFee)->toBe(299)
        ->and($priced->total)->toBe(1700 + 299);
});

it('reads an order back the way the agent has to say it', function (): void {
    DeliveryFeeRule::factory()->for($this->restaurant)->band(upToMetres: null, fee: 299)->create();

    $chips = MenuItem::factory()->for($this->restaurant)->pricedAt(350)->create(['name' => 'Chips']);

    $priced = $this->pricing->price(cart(FulfilmentType::Delivery, [
        new CartLine($this->burger, quantity: 2, modifiers: [
            new CartModifier($this->cheese),
            new CartModifier($this->onions),
        ]),
        new CartLine($chips),
    ], distance: 1200));

    expect($priced->spokenReadBack())->toBe(
        '2 Ember Chicken Burger with Extra Cheese, 1 pound and no onions, 19 pounds. '
        .'Chips, 3 pounds 50. '
        .'Delivery, 2 pounds 99. '
        .'That comes to 25 pounds 49.',
    );
});

it('snapshots a line so a later price rise cannot rewrite it', function (): void {
    $priced = $this->pricing->price(cart(FulfilmentType::Collection, [
        new CartLine($this->burger, modifiers: [new CartModifier($this->cheese)], notes: 'well done'),
    ]));

    $line = $priced->lines[0];

    expect($line->name)->toBe('Ember Chicken Burger')
        ->and($line->sku)->toBe($this->burger->sku)
        ->and($line->notes)->toBe('well done')
        ->and($line->toSnapshot())->toHaveCount(1)
        ->and($line->toSnapshot()[0])->toMatchArray([
            'name' => 'Extra Cheese',
            'price_delta' => 100,
            'quantity' => 1,
        ]);
});
