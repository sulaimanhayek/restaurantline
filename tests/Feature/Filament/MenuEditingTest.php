<?php

declare(strict_types=1);

use App\Enums\ModifierGroupSelectionType;
use App\Enums\ModifierKind;
use App\Filament\Resources\MenuItems\Pages\CreateMenuItem;
use App\Filament\Resources\MenuItems\Pages\EditMenuItem;
use App\Filament\Resources\ModifierGroups\Pages\CreateModifierGroup;
use App\Filament\Resources\ModifierGroups\Pages\EditModifierGroup;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Editing the menu
|--------------------------------------------------------------------------
|
| This is the part of the dashboard the person setting up a new restaurant
| actually spends their afternoon in, so it is the part worth pinning down:
| prices arrive in pounds and land as pence, aliases survive the round trip,
| and a tenant column nobody typed gets filled in.
|
*/

beforeEach(function (): void {
    $this->restaurant = restaurant(['currency' => 'GBP']);
    $this->category = MenuCategory::factory()->for($this->restaurant)->create(['name' => 'Burgers']);

    actingAs(User::factory()->create(['restaurant_id' => $this->restaurant->id]));
});

describe('menu items', function (): void {
    it('creates a dish', function (): void {
        Livewire::test(CreateMenuItem::class)
            ->fillForm([
                'name' => 'Ember Chicken Burger',
                'slug' => 'ember-chicken-burger',
                'menu_category_id' => $this->category->id,
                'price' => '9.50',
                'spoken_aliases' => ['the chicken one', 'peri chicken burger'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = MenuItem::query()->where('slug', 'ember-chicken-burger')->sole();

        expect($item->price)->toBe(950)
            ->and($item->restaurant_id)->toBe($this->restaurant->id)
            ->and($item->spoken_aliases)->toBe(['the chicken one', 'peri chicken burger'])
            ->and($item->is_available)->toBeTrue();
    });

    it('will not create a dish without a price or a category', function (): void {
        Livewire::test(CreateMenuItem::class)
            ->fillForm(['name' => 'Nameless'])
            ->call('create')
            ->assertHasFormErrors(['menu_category_id', 'price']);

        expect(MenuItem::query()->count())->toBe(0);
    });

    it('derives the slug from the name, once', function (): void {
        Livewire::test(CreateMenuItem::class)
            ->fillForm(['name' => 'Half Peri Chicken'])
            ->assertFormSet(['slug' => 'half-peri-chicken'])
            // Renaming a dish must not silently change the slug: the slug is
            // what the agent's tool calls and any menu import refer to.
            ->fillForm(['name' => 'Half Peri Chicken (new recipe)'])
            ->assertFormSet(['slug' => 'half-peri-chicken']);
    });

    it('edits a price without disturbing it', function (): void {
        $item = MenuItem::factory()->for($this->restaurant)
            ->for($this->category, 'category')
            ->create(['price' => 1250, 'spoken_aliases' => ['the big one']]);

        Livewire::test(EditMenuItem::class, ['record' => $item->getKey()])
            ->assertFormSet(['price' => '12.50', 'spoken_aliases' => ['the big one']])
            ->fillForm(['price' => '13.95'])
            ->call('save')
            ->assertHasNoFormErrors();

        $item->refresh();

        expect($item->price)->toBe(1395)
            ->and($item->spoken_aliases)->toBe(['the big one']);
    });

    it('attaches modifier groups', function (): void {
        $group = ModifierGroup::factory()->for($this->restaurant)->create();
        $item = MenuItem::factory()->for($this->restaurant)->for($this->category, 'category')->create();

        Livewire::test(EditMenuItem::class, ['record' => $item->getKey()])
            ->fillForm(['modifierGroups' => [$group->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($item->fresh()->modifierGroups->pluck('id')->all())->toBe([$group->id]);
    });
});

describe('modifier groups', function (): void {
    it('creates a group and its options in one save', function (): void {
        Livewire::test(CreateModifierGroup::class)
            ->fillForm([
                'name' => 'Size',
                'slug' => 'size',
                'selection_type' => ModifierGroupSelectionType::Single->value,
                'is_required' => true,
                'min_selections' => 1,
                'modifiers' => [
                    ['name' => 'Regular', 'slug' => 'size-regular', 'kind' => ModifierKind::Option->value, 'price_delta' => '0', 'is_default' => true, 'is_available' => true],
                    ['name' => 'Large', 'slug' => 'size-large', 'kind' => ModifierKind::Option->value, 'price_delta' => '1.50', 'is_available' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $group = ModifierGroup::query()->where('slug', 'size')->sole();

        expect($group->restaurant_id)->toBe($this->restaurant->id)
            ->and($group->is_required)->toBeTrue()
            ->and($group->modifiers()->orderBy('sort_order')->pluck('name')->all())
            ->toBe(['Regular', 'Large']);
    });

    /*
     * The reason the repeater carries mutateRelationshipDataBeforeCreateUsing:
     * a repeater row has no idea which restaurant it belongs to, and
     * restaurant_id is NOT NULL. Without it this is a 500 on somebody's first
     * attempt to add a size option.
     */
    it('stamps the tenant onto options created in the repeater', function (): void {
        Livewire::test(CreateModifierGroup::class)
            ->fillForm([
                'name' => 'Sauces',
                'slug' => 'sauces',
                'selection_type' => ModifierGroupSelectionType::Multi->value,
                'min_selections' => 0,
                'max_selections' => 3,
                'modifiers' => [
                    ['name' => 'Garlic', 'slug' => 'sauce-garlic', 'kind' => ModifierKind::Addon->value, 'price_delta' => '0.50', 'is_available' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $sauce = Modifier::query()->where('slug', 'sauce-garlic')->sole();

        expect($sauce->restaurant_id)->toBe($this->restaurant->id)
            ->and($sauce->price_delta)->toBe(50);
    });

    it('keeps a negative price change negative', function (): void {
        $group = ModifierGroup::factory()->for($this->restaurant)->create();

        Livewire::test(EditModifierGroup::class, ['record' => $group->getKey()])
            ->fillForm([
                'modifiers' => [
                    ['name' => 'No cheese', 'slug' => 'no-cheese', 'kind' => ModifierKind::Removal->value, 'price_delta' => '-0.40', 'is_available' => true],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(Modifier::query()->where('slug', 'no-cheese')->sole()->price_delta)->toBe(-40);
    });
});
