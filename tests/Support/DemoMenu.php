<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\DeliveryFeeRule;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Restaurant;

/**
 * A menu small enough to reason about and awkward enough to be worth testing.
 *
 * The agent endpoint tests share it rather than each building their own, so a
 * failure in one file means the same thing as a failure in another. It is
 * deliberately not the demo seed: that one is a showcase and will grow, and a
 * test asserting on a total is a test that must not change when somebody adds
 * a dessert.
 *
 * What it contains, and why:
 *
 *  - two burgers with overlapping names, so an ambiguous query has something to
 *    be ambiguous between;
 *  - an item that is sold out and one in a lunch-only category, which are the
 *    two different ways an item can be on the menu and not orderable;
 *  - a required single-choice group and an optional multi group, because a
 *    required choice the caller never made is the modifier bug people hit;
 *  - a removal, a swap and an add-on, since all three price differently;
 *  - an unavailable modifier, which fails differently from an unknown one.
 */
final class DemoMenu
{
    public MenuCategory $burgers;

    public MenuCategory $lunch;

    public MenuItem $emberBurger;

    public MenuItem $beefBurger;

    public MenuItem $chips;

    public MenuItem $soldOut;

    public MenuItem $lunchWrap;

    public ModifierGroup $bun;

    public ModifierGroup $extras;

    public Modifier $brioche;

    public Modifier $glutenFree;

    public Modifier $cheese;

    public Modifier $bacon;

    public Modifier $onions;

    public function __construct(public Restaurant $restaurant)
    {
        $this->burgers = MenuCategory::factory()->for($restaurant)
            ->create(['name' => 'Burgers', 'slug' => 'burgers', 'sort_order' => 0]);

        $this->lunch = MenuCategory::factory()->for($restaurant)
            ->availableBetween('11:30:00', '15:00:00')
            ->create(['name' => 'Lunch', 'slug' => 'lunch', 'sort_order' => 1]);

        $this->emberBurger = MenuItem::factory()->for($restaurant)->for($this->burgers, 'category')
            ->pricedAt(850)
            ->withAliases(['chicken burger', 'ember burger'])
            ->create(['name' => 'Ember Chicken Burger', 'slug' => 'ember-chicken-burger']);

        $this->beefBurger = MenuItem::factory()->for($restaurant)->for($this->burgers, 'category')
            ->pricedAt(1050)
            ->withAliases(['beef burger'])
            ->create(['name' => 'Double Beef Burger', 'slug' => 'double-beef-burger']);

        $this->chips = MenuItem::factory()->for($restaurant)->for($this->burgers, 'category')
            ->pricedAt(350)
            ->create(['name' => 'Chips', 'slug' => 'chips']);

        $this->soldOut = MenuItem::factory()->for($restaurant)->for($this->burgers, 'category')
            ->pricedAt(700)
            ->unavailable()
            ->create(['name' => 'Buttermilk Wings', 'slug' => 'buttermilk-wings']);

        $this->lunchWrap = MenuItem::factory()->for($restaurant)->for($this->lunch, 'category')
            ->pricedAt(900)
            ->create(['name' => 'Lunch Wrap Meal', 'slug' => 'lunch-wrap-meal']);

        $this->bun = ModifierGroup::factory()->for($restaurant)
            ->requiredSingle()
            ->create(['name' => 'Bun', 'slug' => 'bun', 'sort_order' => 0]);

        $this->brioche = Modifier::factory()->for($restaurant)->for($this->bun, 'group')
            ->defaultChoice()
            ->create(['name' => 'Brioche Bun', 'slug' => 'brioche-bun']);

        $this->glutenFree = Modifier::factory()->for($restaurant)->for($this->bun, 'group')
            ->swap(priceDelta: 50)
            ->create(['name' => 'Gluten-Free Bun', 'slug' => 'gluten-free-bun']);

        $this->extras = ModifierGroup::factory()->for($restaurant)
            ->multi(max: 5)
            ->create(['name' => 'Extras', 'slug' => 'extras', 'sort_order' => 1]);

        $this->cheese = Modifier::factory()->for($restaurant)->for($this->extras, 'group')
            ->addon(priceDelta: 100)
            ->create(['name' => 'Extra Cheese', 'slug' => 'extra-cheese']);

        $this->bacon = Modifier::factory()->for($restaurant)->for($this->extras, 'group')
            ->addon(priceDelta: 150)
            ->unavailable()
            ->create(['name' => 'Smoked Bacon', 'slug' => 'smoked-bacon']);

        $this->onions = Modifier::factory()->for($restaurant)->for($this->extras, 'group')
            ->removal()
            ->create(['name' => 'Onions', 'slug' => 'onions']);

        $this->emberBurger->modifierGroups()->attach([$this->bun->id, $this->extras->id]);
        $this->beefBurger->modifierGroups()->attach([$this->bun->id, $this->extras->id]);

        DeliveryFeeRule::factory()->for($restaurant)->band(upToMetres: 2000, fee: 199)->create();
        DeliveryFeeRule::factory()->for($restaurant)->band(upToMetres: null, fee: 349, sortOrder: 1)->create();
    }
}
