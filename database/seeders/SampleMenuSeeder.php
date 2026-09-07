<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ModifierGroupSelectionType;
use App\Enums\ModifierKind;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Ember Grill's menu.
 *
 * Every structure the modifier schema supports is exercised at least once,
 * because the point of a demo menu is to break the code before a real client
 * does: a required size choice, free options, priced add-ons, removals that
 * cost nothing but must reach the kitchen ticket, swaps, a per-item selection
 * limit, a per-item price override and a per-item availability override.
 *
 * `spoken_aliases` is the field that matters most here. It is what the agent's
 * menu tool matches against, and it should hold what callers actually say
 * ("half a chicken", "coke", "no salad") rather than what the menu prints.
 */
class SampleMenuSeeder extends Seeder
{
    public function run(): void
    {
        $restaurant = Restaurant::query()->where('slug', 'ember-grill')->firstOrFail();

        $groups = $this->seedModifierGroups($restaurant);
        $categories = $this->seedCategories($restaurant);

        $this->seedItems($restaurant, $categories, $groups);
    }

    /**
     * @return array<string, ModifierGroup>
     */
    private function seedModifierGroups(Restaurant $restaurant): array
    {
        /**
         * slug => [name, prompt, selection type, min, max, required, sort, modifiers]
         * A modifier is [name, price delta, kind, aliases, is default].
         *
         * @var array<string, array{string, string|null, ModifierGroupSelectionType, int, int|null, bool, int, list<array{string, int, ModifierKind, list<string>, bool}>}>
         */
        $definitions = [
            'spice-level' => [
                'Spice level',
                'How hot would you like it?',
                ModifierGroupSelectionType::Single, 1, 1, true, 0,
                [
                    ['Plain', 0, ModifierKind::Option, ['plain', 'no spice', 'not spicy', 'mild', 'nothing on it'], true],
                    ['Lemon & Herb', 0, ModifierKind::Option, ['lemon and herb', 'lemon herb', 'lemon', 'herb'], false],
                    ['Medium', 0, ModifierKind::Option, ['medium', 'normal', 'a bit spicy'], false],
                    ['Hot', 0, ModifierKind::Option, ['hot', 'spicy'], false],
                    ['Extra Hot', 0, ModifierKind::Option, ['extra hot', 'very hot', 'as hot as it goes', 'super spicy'], false],
                ],
            ],
            'chicken-size' => [
                'Chicken size',
                'Quarter, half or whole chicken?',
                ModifierGroupSelectionType::Single, 1, 1, true, 1,
                [
                    ['Quarter Chicken', 0, ModifierKind::Option, ['quarter', 'quarter chicken', 'a quarter'], true],
                    ['Half Chicken', 350, ModifierKind::Option, ['half', 'half chicken', 'half a chicken'], false],
                    ['Whole Chicken', 700, ModifierKind::Option, ['whole', 'whole chicken', 'full chicken', 'a whole one'], false],
                ],
            ],
            'portion-size' => [
                'Portion size',
                'Regular or large?',
                ModifierGroupSelectionType::Single, 1, 1, true, 2,
                [
                    ['Regular', 0, ModifierKind::Option, ['regular', 'normal', 'standard', 'small'], true],
                    ['Large', 150, ModifierKind::Option, ['large', 'big', 'big one', 'go large'], false],
                ],
            ],
            'drink-size' => [
                'Drink size',
                'Can or bottle?',
                ModifierGroupSelectionType::Single, 1, 1, true, 3,
                [
                    ['Can', 0, ModifierKind::Option, ['can', 'a can', 'small'], true],
                    ['500ml Bottle', 70, ModifierKind::Option, ['bottle', 'big bottle', 'large', 'five hundred mil'], false],
                ],
            ],
            'burger-bun' => [
                'Bun',
                'Which bun would you like?',
                ModifierGroupSelectionType::Single, 1, 1, true, 4,
                [
                    ['Brioche Bun', 0, ModifierKind::Option, ['brioche', 'normal bun', 'regular bun'], true],
                    ['Gluten-Free Bun', 100, ModifierKind::Swap, ['gluten free', 'gluten free bun', 'gf bun'], false],
                    ['Lettuce Wrap', 0, ModifierKind::Swap, ['lettuce wrap', 'no bun', 'protein style', 'bunless'], false],
                ],
            ],
            'burger-extras' => [
                'Extras',
                'Anything extra on that?',
                ModifierGroupSelectionType::Multi, 0, null, false, 5,
                [
                    ['Extra Cheese', 100, ModifierKind::Addon, ['extra cheese', 'more cheese', 'cheese'], false],
                    ['Bacon', 150, ModifierKind::Addon, ['bacon', 'add bacon', 'streaky bacon'], false],
                    ['Extra Patty', 300, ModifierKind::Addon, ['extra patty', 'another patty', 'double it', 'extra burger'], false],
                    ['Grilled Halloumi', 200, ModifierKind::Addon, ['halloumi', 'add halloumi', 'grilled halloumi'], false],
                    ['Avocado', 150, ModifierKind::Addon, ['avocado', 'avo', 'add avocado'], false],
                    ['Jalapeños', 80, ModifierKind::Addon, ['jalapenos', 'jalapeno', 'green chillies', 'peppers'], false],
                ],
            ],
            // A removal is named after the ingredient, not the instruction:
            // `ModifierKind::Removal` supplies the "NO " on the kitchen ticket,
            // and naming these "No Onions" would print "NO NO ONIONS".
            'burger-removals' => [
                'Remove',
                'Anything you would like left off?',
                ModifierGroupSelectionType::Multi, 0, null, false, 6,
                [
                    ['Onions', 0, ModifierKind::Removal, ['no onions', 'without onions', 'hold the onions', 'no onion'], false],
                    ['Pickles', 0, ModifierKind::Removal, ['no pickles', 'without pickles', 'hold the pickles', 'no gherkins'], false],
                    ['Lettuce', 0, ModifierKind::Removal, ['no lettuce', 'without lettuce', 'no salad', 'no greens'], false],
                    ['Tomato', 0, ModifierKind::Removal, ['no tomato', 'without tomato', 'hold the tomato'], false],
                    ['Mayo', 0, ModifierKind::Removal, ['no mayo', 'without mayo', 'no sauce', 'dry'], false],
                    ['Cheese', 0, ModifierKind::Removal, ['no cheese', 'without cheese', 'hold the cheese'], false],
                ],
            ],
            'side-swap' => [
                'Side',
                'It comes with chips — happy with that?',
                ModifierGroupSelectionType::Single, 1, 1, true, 7,
                [
                    ['Chips', 0, ModifierKind::Option, ['chips', 'fries', 'normal chips', 'regular chips'], true],
                    ['Spicy Chips', 50, ModifierKind::Swap, ['spicy chips', 'peri chips', 'peri peri chips', 'hot chips'], false],
                    ['Sweet Potato Fries', 150, ModifierKind::Swap, ['sweet potato', 'sweet potato fries', 'sweet fries'], false],
                    ['Spicy Rice', 0, ModifierKind::Swap, ['rice', 'spicy rice', 'rice instead'], false],
                    ['Side Salad', 0, ModifierKind::Swap, ['salad', 'side salad', 'salad instead', 'no chips'], false],
                ],
            ],
            'dips' => [
                'Dips',
                'Any dips with that?',
                ModifierGroupSelectionType::Multi, 0, 3, false, 8,
                [
                    ['Peri Mayo', 60, ModifierKind::Addon, ['peri mayo', 'peri peri mayo', 'spicy mayo'], false],
                    ['Garlic Aioli', 60, ModifierKind::Addon, ['garlic', 'aioli', 'garlic aioli', 'garlic mayo'], false],
                    ['Smoky BBQ', 60, ModifierKind::Addon, ['bbq', 'barbecue', 'barbeque sauce', 'smoky bbq'], false],
                    ['Sweet Chilli', 60, ModifierKind::Addon, ['sweet chilli', 'sweet chili', 'chilli sauce'], false],
                ],
            ],
        ];

        $groups = [];

        foreach ($definitions as $slug => [$name, $prompt, $type, $min, $max, $required, $sort, $modifiers]) {
            $group = ModifierGroup::query()->updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'slug' => $slug],
                [
                    'name' => $name,
                    'prompt' => $prompt,
                    'selection_type' => $type,
                    'min_selections' => $min,
                    'max_selections' => $max,
                    'is_required' => $required,
                    'sort_order' => $sort,
                ],
            );

            foreach ($modifiers as $index => [$modName, $delta, $kind, $aliases, $isDefault]) {
                // Keyed on restaurant and slug alone, matching the unique
                // index (#0018). Including the group would mean moving a
                // modifier between groups collided with itself on re-seed.
                Modifier::query()->updateOrCreate(
                    [
                        'restaurant_id' => $restaurant->id,
                        'slug' => Str::slug($modName),
                    ],
                    [
                        'modifier_group_id' => $group->id,
                        'name' => $modName,
                        'price_delta' => $delta,
                        'kind' => $kind,
                        'spoken_aliases' => $aliases,
                        'is_available' => true,
                        'is_default' => $isDefault,
                        'sort_order' => $index,
                    ],
                );
            }

            $groups[$slug] = $group;
        }

        return $groups;
    }

    /**
     * @return array<string, MenuCategory>
     */
    private function seedCategories(Restaurant $restaurant): array
    {
        /** @var array<string, array{string, string|null, int, string|null, string|null, list<int>|null}> $definitions */
        $definitions = [
            'starters' => ['Starters', 'Something to pick at while the grill does its work.', 0, null, null, null],
            'flame-grilled' => ['Flame-Grilled Chicken', 'Marinated for 24 hours, grilled to order.', 1, null, null, null],
            'burgers' => ['Burgers', null, 2, null, null, null],
            'wraps-and-pittas' => ['Wraps & Pittas', null, 3, null, null, null],
            'sides' => ['Sides', null, 4, null, null, null],
            'desserts' => ['Desserts', null, 5, null, null, null],
            'drinks' => ['Drinks', null, 6, null, null, null],
            // Weekday lunchtimes only. The agent must not offer this at 8pm,
            // and must not offer it on a Sunday.
            'lunch-deals' => ['Lunch Deals', 'Weekdays, 11:30 to 3pm.', 7, '11:30:00', '15:00:00', [1, 2, 3, 4, 5]],
        ];

        $categories = [];

        foreach ($definitions as $slug => [$name, $description, $sort, $from, $until, $days]) {
            $categories[$slug] = MenuCategory::query()->updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'slug' => $slug],
                [
                    'name' => $name,
                    'description' => $description,
                    'sort_order' => $sort,
                    'is_active' => true,
                    'available_from' => $from,
                    'available_until' => $until,
                    'available_days' => $days,
                ],
            );
        }

        return $categories;
    }

    /**
     * @param  array<string, MenuCategory>  $categories
     * @param  array<string, ModifierGroup>  $groups
     */
    private function seedItems(Restaurant $restaurant, array $categories, array $groups): void
    {
        /**
         * category slug => list of items, each
         * [name, price in pence, description, aliases, attached group slugs, prep minutes].
         *
         * @var array<string, list<array{string, int, string|null, list<string>, list<string>, int|null}>> $menu
         */
        $menu = [
            'starters' => [
                ['Flame-Grilled Wings', 600, 'Five wings, marinated and grilled.', ['wings', 'chicken wings', 'spicy wings', 'hot wings', 'five wings'], ['spice-level', 'dips'], null],
                ['Halloumi Fries', 550, 'With honey and chilli.', ['halloumi', 'halloumi fries', 'cheese fries', 'halloumi sticks'], ['dips'], null],
                ['Corn on the Cob', 250, null, ['corn', 'sweetcorn', 'corn on the cob'], [], null],
                ['Chicken Strips', 700, 'Five butterflied strips.', ['strips', 'chicken strips', 'goujons', 'tenders', 'chicken tenders'], ['spice-level', 'dips'], null],
            ],
            'flame-grilled' => [
                ['Flame-Grilled Chicken', 750, 'Quarter, half or whole, with one side.', ['chicken', 'grilled chicken', 'peri peri chicken', 'flame grilled chicken', 'peri chicken'], ['chicken-size', 'spice-level', 'side-swap'], 25],
                ['Chicken Platter', 1650, 'A whole chicken with two large sides.', ['platter', 'family platter', 'sharing platter', 'whole chicken platter'], ['spice-level', 'dips'], 30],
            ],
            'burgers' => [
                ['Ember Chicken Burger', 850, 'Butterflied breast, brioche, peri mayo.', ['chicken burger', 'ember burger', 'peri burger', 'the ember'], ['burger-bun', 'spice-level', 'burger-extras', 'burger-removals', 'side-swap'], null],
                ['Double Beef Burger', 1050, 'Two smashed patties, cheese, pickles.', ['double beef', 'beef burger', 'double burger', 'double cheeseburger', 'cheeseburger'], ['burger-bun', 'burger-extras', 'burger-removals', 'side-swap'], null],
                ['Halloumi & Avocado Burger', 900, 'Grilled halloumi, avocado, chilli jam.', ['halloumi burger', 'veggie burger', 'vegetarian burger', 'the veggie'], ['burger-bun', 'burger-extras', 'burger-removals', 'side-swap'], null],
            ],
            'wraps-and-pittas' => [
                ['Chicken Wrap', 750, null, ['wrap', 'chicken wrap', 'peri wrap'], ['spice-level', 'burger-extras', 'burger-removals'], null],
                ['Chicken Pitta', 700, null, ['pitta', 'pita', 'chicken pitta', 'chicken pita'], ['spice-level', 'burger-removals'], null],
            ],
            'sides' => [
                ['Chips', 300, null, ['chips', 'fries', 'french fries'], ['portion-size'], null],
                ['Spicy Rice', 350, null, ['rice', 'spicy rice', 'peri rice'], ['portion-size'], null],
                ['Coleslaw', 250, null, ['slaw', 'coleslaw'], ['portion-size'], null],
                ['Garlic Bread', 350, null, ['garlic bread', 'bread'], [], null],
                ['Macho Peas', 300, 'Crushed peas, mint, chilli.', ['peas', 'macho peas', 'mushy peas'], [], null],
            ],
            'desserts' => [
                ['Chocolate Brownie', 450, null, ['brownie', 'chocolate brownie'], [], null],
                ['Vanilla Cheesecake', 450, null, ['cheesecake', 'vanilla cheesecake'], [], null],
            ],
            'drinks' => [
                ['Coca-Cola', 180, null, ['coke', 'cola', 'coca cola'], ['drink-size'], null],
                ['Diet Coke', 180, null, ['diet coke', 'coke zero', 'diet cola', 'diet'], ['drink-size'], null],
                ['Fanta Orange', 180, null, ['fanta', 'orange', 'orangeade'], ['drink-size'], null],
                ['Still Water', 120, null, ['water', 'still water', 'bottle of water'], [], null],
            ],
            'lunch-deals' => [
                ['Lunch Wrap Meal', 895, 'Chicken wrap, regular chips and a can.', ['lunch deal', 'meal deal', 'lunch meal', 'wrap meal', 'the lunch one'], ['spice-level', 'side-swap'], null],
            ],
        ];

        foreach ($menu as $categorySlug => $items) {
            $category = $categories[$categorySlug];

            foreach ($items as $sortOrder => [$name, $price, $description, $aliases, $groupSlugs, $prepMinutes]) {
                $item = MenuItem::query()->updateOrCreate(
                    ['restaurant_id' => $restaurant->id, 'slug' => Str::slug($name)],
                    [
                        'menu_category_id' => $category->id,
                        'name' => $name,
                        'description' => $description,
                        'price' => $price,
                        'sku' => Str::upper(Str::slug($name, '')),
                        'is_available' => true,
                        'spoken_aliases' => $aliases,
                        'sort_order' => $sortOrder,
                        'prep_minutes' => $prepMinutes,
                    ],
                );

                $attach = [];

                foreach ($groupSlugs as $index => $groupSlug) {
                    $attach[$groups[$groupSlug]->id] = ['sort_order' => $index];
                }

                $item->modifierGroups()->sync($attach);
            }
        }

        $this->seedPerItemOverrides($restaurant, $groups);
    }

    /**
     * The three overrides that prove the pivot columns earn their keep.
     *
     * @param  array<string, ModifierGroup>  $groups
     */
    private function seedPerItemOverrides(Restaurant $restaurant, array $groups): void
    {
        $items = MenuItem::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('slug', ['ember-chicken-burger', 'double-beef-burger', 'halloumi-avocado-burger'])
            ->get()
            ->keyBy('slug');

        $modifiers = Modifier::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('slug', ['extra-patty', 'bacon'])
            ->get()
            ->keyBy('slug');

        // The chicken burger is already a full breast — cap the pile-on at three.
        $items['ember-chicken-burger']->modifierGroups()->updateExistingPivot(
            $groups['burger-extras']->id,
            ['max_selections_override' => 3],
        );

        // A third patty on a burger that already has two is cheaper than the
        // first extra patty elsewhere on the menu.
        $items['double-beef-burger']->modifierOverrides()->syncWithoutDetaching([
            $modifiers['extra-patty']->id => ['price_delta_override' => 250],
        ]);

        // Bacon exists on the menu but not on the vegetarian burger. Hiding it
        // here rather than deleting it keeps one shared Extras group.
        $items['halloumi-avocado-burger']->modifierOverrides()->syncWithoutDetaching([
            $modifiers['bacon']->id => ['is_available_override' => false],
        ]);
    }
}
