<?php

declare(strict_types=1);

use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;

/**
 * The per-item override rules from DECISIONS #0002 and #0003. Get these wrong
 * and every price on every ticket is quietly wrong, so they are tested
 * directly rather than only through the pricing service.
 */
beforeEach(function (): void {
    $this->restaurant = restaurant();

    $this->group = ModifierGroup::factory()
        ->for($this->restaurant)
        ->multi(max: 5)
        ->create(['slug' => 'extras']);

    $this->cheese = Modifier::factory()
        ->for($this->restaurant)
        ->for($this->group, 'group')
        ->addon(priceDelta: 100)
        ->create(['name' => 'Extra Cheese', 'slug' => 'extra-cheese']);

    $this->item = MenuItem::factory()->for($this->restaurant)->pricedAt(850)->create();
    $this->item->modifierGroups()->attach($this->group);
});

it('falls back to the group rules when the item sets no override', function (): void {
    $rules = $this->item->fresh()->resolvedSelectionRules($this->group);

    expect($rules['min'])->toBe(0)
        ->and($rules['max'])->toBe(5)
        ->and($rules['required'])->toBeFalse();
});

it('lets one item tighten the selection limit without touching the shared group', function (): void {
    $this->item->modifierGroups()->updateExistingPivot($this->group->id, [
        'max_selections_override' => 2,
        'is_required_override' => true,
    ]);

    $rules = $this->item->fresh()->resolvedSelectionRules($this->group);

    expect($rules['max'])->toBe(2)
        ->and($rules['required'])->toBeTrue()
        // The group itself is untouched, so every other item is unaffected.
        ->and($this->group->fresh()->max_selections)->toBe(5);
});

it('treats a zero override as a real value, not as absent', function (): void {
    $group = ModifierGroup::factory()->for($this->restaurant)->requiredSingle()->create();
    $this->item->modifierGroups()->attach($group, ['min_selections_override' => 0]);

    $rules = $this->item->fresh()->resolvedSelectionRules($group);

    // `??` not `?:` — a zero minimum is a legitimate override of a minimum of one.
    expect($rules['min'])->toBe(0);
});

it('uses the group price when the item has no price override', function (): void {
    expect($this->item->resolvedPriceDelta($this->cheese))->toBe(100);
});

it('lets one item reprice a shared modifier', function (): void {
    $this->item->modifierOverrides()->syncWithoutDetaching([
        $this->cheese->id => ['price_delta_override' => 250],
    ]);

    expect($this->item->fresh()->resolvedPriceDelta($this->cheese))->toBe(250);
});

it('treats a free override as a real value', function (): void {
    $this->item->modifierOverrides()->syncWithoutDetaching([
        $this->cheese->id => ['price_delta_override' => 0],
    ]);

    expect($this->item->fresh()->resolvedPriceDelta($this->cheese))->toBe(0);
});

it('collects the terms a caller might actually say', function (): void {
    $item = MenuItem::factory()
        ->for($this->restaurant)
        ->withAliases(['Peri Peri Chicken', 'grilled chicken', 'peri peri chicken'])
        ->create(['name' => 'Flame-Grilled Chicken']);

    expect($item->matchableTerms())
        ->toContain('flame-grilled chicken')
        ->toContain('peri peri chicken')
        ->toContain('grilled chicken')
        // Deduplicated after lowercasing, so the matcher does not double-count.
        ->toHaveCount(3);
});

it('names a removal after the ingredient and lets the kind supply the "no"', function (): void {
    $onions = Modifier::factory()
        ->for($this->restaurant)
        ->for($this->group, 'group')
        ->removal()
        ->create(['name' => 'Onions', 'spoken_aliases' => ['no onions', 'hold the onions']]);

    expect($onions->ticketLabel())->toBe('NO Onions')
        ->and($onions->isFree())->toBeTrue()
        ->and($onions->matchableTerms())->toContain('no onions');
});
