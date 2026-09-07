<?php

declare(strict_types=1);

use App\Enums\ModifierKind;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Services\Menu\MenuMatchingService;
use Carbon\CarbonImmutable;

/**
 * The matcher is what stands between "erm, the peri peri chicken thing" and a
 * row in the menu table. It runs in PostgreSQL — see DECISIONS #0013 — so these
 * are feature tests against a real database rather than unit tests against a
 * reimplementation of trigram similarity.
 */
beforeEach(function (): void {
    $this->restaurant = restaurant();
    $this->matcher = app(MenuMatchingService::class);

    $this->burgers = MenuCategory::factory()->for($this->restaurant)
        ->create(['name' => 'Burgers', 'slug' => 'burgers']);

    $this->lunch = MenuCategory::factory()->for($this->restaurant)
        ->availableBetween('11:30:00', '15:00:00')
        ->create(['name' => 'Lunch', 'slug' => 'lunch']);

    $this->emberBurger = MenuItem::factory()->for($this->restaurant)->for($this->burgers, 'category')
        ->withAliases(['chicken burger', 'ember burger'])
        ->create(['name' => 'Ember Chicken Burger', 'slug' => 'ember-chicken-burger']);

    $this->beefBurger = MenuItem::factory()->for($this->restaurant)->for($this->burgers, 'category')
        ->withAliases(['beef burger'])
        ->create(['name' => 'Double Beef Burger', 'slug' => 'double-beef-burger']);

    $this->veggieBurger = MenuItem::factory()->for($this->restaurant)->for($this->burgers, 'category')
        ->withAliases(['veggie burger', 'halloumi burger'])
        ->create(['name' => 'Halloumi & Avocado Burger', 'slug' => 'halloumi-avocado-burger']);

    $this->periPeri = MenuItem::factory()->for($this->restaurant)->for($this->burgers, 'category')
        ->withAliases(['peri peri chicken', 'peri chicken'])
        ->create(['name' => 'Flame-Grilled Chicken', 'slug' => 'flame-grilled-chicken']);

    $this->chips = MenuItem::factory()->for($this->restaurant)->for($this->burgers, 'category')
        ->create(['name' => 'Chips', 'slug' => 'chips']);

    $this->lunchWrap = MenuItem::factory()->for($this->restaurant)->for($this->lunch, 'category')
        ->withAliases(['lunch deal'])
        ->create(['name' => 'Lunch Wrap Meal', 'slug' => 'lunch-wrap-meal']);
});

it('matches an item said by its exact name', function (): void {
    $result = $this->matcher->search($this->restaurant, 'Ember Chicken Burger');

    expect($result->best()?->item->id)->toBe($this->emberBurger->id)
        ->and($result->best()?->confidence)->toBe(1.0)
        ->and($result->best()?->isExact())->toBeTrue()
        ->and($result->isConfident())->toBeTrue()
        ->and($result->isAmbiguous())->toBeFalse();
});

it('matches an alias the way a caller actually says it', function (string $query, string $expected): void {
    $result = $this->matcher->search($this->restaurant, $query);

    expect($result->best()?->item->name)->toBe($expected)
        ->and($result->isConfident())->toBeTrue();
})->with([
    'the alias alone' => ['peri peri chicken', 'Flame-Grilled Chicken'],
    'wrapped in politeness' => ['erm can I get the peri peri chicken please', 'Flame-Grilled Chicken'],
    'a shorter alias' => ['peri chicken', 'Flame-Grilled Chicken'],
    'an ampersand spelled out' => ['halloumi and avocado burger', 'Halloumi & Avocado Burger'],
    'no alias at all' => ['chips', 'Chips'],
    'a plural the menu does not use' => ['chicken burgers', 'Ember Chicken Burger'],
]);

it('reports a query that fits several items as ambiguous', function (): void {
    // Three burgers, all with "burger" in an alias. Guessing one is worse than
    // asking which.
    $result = $this->matcher->search($this->restaurant, 'burger');

    expect($result->isAmbiguous())->toBeTrue()
        ->and($result->isConfident())->toBeFalse()
        ->and($result->alternatives())->toHaveCount(3);
});

it('is not ambiguous when one item is a clear winner', function (): void {
    $result = $this->matcher->search($this->restaurant, 'beef burger');

    expect($result->isAmbiguous())->toBeFalse()
        ->and($result->best()?->item->id)->toBe($this->beefBurger->id);
});

it('returns nothing rather than a bad guess', function (): void {
    $result = $this->matcher->search($this->restaurant, 'sushi platter');

    expect($result->isEmpty())->toBeTrue()
        ->and($result->best())->toBeNull();
});

it('returns nothing for a query that normalises away', function (): void {
    expect($this->matcher->search($this->restaurant, '   ...   ')->isEmpty())->toBeTrue();
});

it('never reaches into another restaurant\'s menu', function (): void {
    $other = restaurant(['name' => 'Somebody Else']);
    $category = MenuCategory::factory()->for($other)->create();

    $theirBurger = MenuItem::factory()->for($other)->for($category, 'category')
        ->create(['name' => 'Ember Chicken Burger', 'slug' => 'their-ember-chicken-burger']);

    $theirs = MenuItem::factory()->for($other)->for($category, 'category')
        ->create(['name' => 'Katsu Curry Bowl', 'slug' => 'katsu-curry-bowl']);

    // A dish only they sell is invisible to us.
    expect($this->matcher->search($this->restaurant, 'katsu curry bowl')->isEmpty())->toBeTrue();

    // And where both menus carry the same name, each restaurant gets its own
    // row — the failure that would put another client's item on a ticket.
    expect($this->matcher->search($other, 'ember chicken burger')->best()?->item->id)
        ->toBe($theirBurger->id)
        ->and($this->matcher->search($this->restaurant, 'ember chicken burger')->best()?->item->id)
        ->toBe($this->emberBurger->id)
        ->and($this->matcher->search($other, 'katsu curry bowl')->best()?->item->id)
        ->toBe($theirs->id);
});

it('honours the result limit', function (): void {
    expect($this->matcher->search($this->restaurant, 'burger', limit: 2)->matches)->toHaveCount(2);
});

describe('availability', function (): void {
    it('still finds a sold-out item, and says so', function (): void {
        $this->chips->update(['is_available' => false]);

        $match = $this->matcher->search($this->restaurant, 'chips')->best();

        expect($match?->item->id)->toBe($this->chips->id)
            ->and($match?->isAvailableNow)->toBeFalse()
            ->and($match?->unavailableReason)->toBe('sold out');
    });

    it('explains the window an item is outside of', function (): void {
        $match = $this->matcher->search(
            $this->restaurant,
            'lunch deal',
            at: CarbonImmutable::parse('2026-09-07 19:00:00'),
        )->best();

        expect($match?->isAvailableNow)->toBeFalse()
            ->and($match?->unavailableReason)->toBe('only served 11:30am to 3pm');
    });

    it('serves the same item inside its window', function (): void {
        $match = $this->matcher->search(
            $this->restaurant,
            'lunch deal',
            at: CarbonImmutable::parse('2026-09-07 12:30:00'),
        )->best();

        expect($match?->isAvailableNow)->toBeTrue()
            ->and($match?->unavailableReason)->toBeNull();
    });
});

describe('modifier matching', function (): void {
    beforeEach(function (): void {
        $this->extras = ModifierGroup::factory()->for($this->restaurant)->multi(max: 5)
            ->create(['name' => 'Extras', 'slug' => 'extras']);

        $this->extraCheese = Modifier::factory()->for($this->restaurant)->for($this->extras, 'group')
            ->addon(priceDelta: 100)
            ->create(['name' => 'Extra Cheese', 'slug' => 'extra-cheese', 'spoken_aliases' => ['more cheese']]);

        $this->extraOnions = Modifier::factory()->for($this->restaurant)->for($this->extras, 'group')
            ->addon(priceDelta: 50)
            ->create(['name' => 'Extra Onions', 'slug' => 'extra-onions']);

        // A removal is named after the ingredient, not the instruction —
        // DECISIONS #0009.
        $this->noOnions = Modifier::factory()->for($this->restaurant)->for($this->extras, 'group')
            ->removal()
            ->create(['name' => 'Onions', 'slug' => 'onions']);

        $this->glutenFreeBun = Modifier::factory()->for($this->restaurant)->for($this->extras, 'group')
            ->swap(priceDelta: 75)
            ->create(['name' => 'Gluten-Free Bun', 'slug' => 'gluten-free-bun']);

        $this->emberBurger->modifierGroups()->attach($this->extras);
        $this->emberBurger->refresh();
    });

    it('matches an add-on by name and by alias', function (string $query): void {
        $matches = $this->matcher->matchModifiers($this->emberBurger, $query);

        expect($matches[0]->modifier->id)->toBe($this->extraCheese->id)
            ->and($matches[0]->priceDelta)->toBe(100)
            ->and($matches[0]->isAvailable)->toBeTrue();
    })->with(['extra cheese', 'more cheese']);

    it('sends a negated phrase to the removal row', function (string $query): void {
        $matches = $this->matcher->matchModifiers($this->emberBurger, $query);

        expect($matches)->not->toBeEmpty()
            ->and($matches[0]->modifier->id)->toBe($this->noOnions->id)
            ->and($matches[0]->modifier->kind)->toBe(ModifierKind::Removal);
    })->with(['no onions', 'without onions', 'hold the onions', 'leave off the onions']);

    it('never lets "extra onions" reach the removal row', function (): void {
        // The failure DECISIONS #0009 was written to prevent: a removal named
        // "Onions" scores perfectly against a request for more of them.
        $matches = $this->matcher->matchModifiers($this->emberBurger, 'extra onions');

        expect($matches[0]->modifier->id)->toBe($this->extraOnions->id)
            ->and(array_map(
                static fn ($match): int => $match->modifier->id,
                $matches,
            ))->not->toContain($this->noOnions->id);
    });

    it('treats gluten-free as a swap and not as a request to hold the bun', function (): void {
        $matches = $this->matcher->matchModifiers($this->emberBurger, 'gluten free bun');

        expect($matches[0]->modifier->id)->toBe($this->glutenFreeBun->id)
            ->and($matches[0]->modifier->kind)->toBe(ModifierKind::Swap)
            ->and($matches[0]->priceDelta)->toBe(75);
    });

    it('prices a modifier with the item\'s override', function (): void {
        $this->emberBurger->modifierOverrides()->attach($this->extraCheese->id, ['price_delta_override' => 175]);

        $matches = $this->matcher->matchModifiers($this->emberBurger->fresh(), 'extra cheese');

        expect($matches[0]->priceDelta)->toBe(175);
    });

    it('reports a modifier the item has run out of', function (): void {
        $this->emberBurger->modifierOverrides()->attach($this->extraCheese->id, ['is_available_override' => false]);

        $matches = $this->matcher->matchModifiers($this->emberBurger->fresh(), 'extra cheese');

        expect($matches[0]->isAvailable)->toBeFalse();
    });

    it('returns nothing for a modifier the item does not carry', function (): void {
        expect($this->matcher->matchModifiers($this->emberBurger, 'pineapple'))->toBeEmpty();
    });

    it('returns nothing when a phrase is nothing but negation', function (): void {
        expect($this->matcher->matchModifiers($this->emberBurger, 'no'))->toBeEmpty();
    });
});
