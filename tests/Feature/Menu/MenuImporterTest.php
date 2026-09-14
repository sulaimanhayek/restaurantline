<?php

declare(strict_types=1);

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Services\Menu\Import\MenuImporter;
use App\Services\Menu\Import\MenuImportException;
use App\Services\Menu\Import\MenuImportReport;

/**
 * Putting a menu into the database, twice.
 *
 * The property that matters most here is not that an import works — it is that
 * running it again does almost nothing. A menu file gets corrected four times
 * on the first day, and an importer that duplicates the menu on each pass is an
 * importer nobody runs a second time.
 */
/**
 * @param  array<string, mixed>  $overrides
 * @return array{categories: list<array<string, mixed>>, modifier_groups: list<array<string, mixed>>}
 */
function importable(array $overrides = []): array
{
    return array_replace([
        'categories' => [
            [
                'name' => 'Mains',
                'items' => [
                    ['name' => 'Chicken Korma', 'price' => '11.90', 'aliases' => ['korma']],
                ],
            ],
        ],
        'modifier_groups' => [],
    ], $overrides);
}

/**
 * @param  array{categories: list<array<string, mixed>>, modifier_groups: list<array<string, mixed>>}  $data
 */
function importMenu(array $data, bool $prune = false): MenuImportReport
{
    return app(MenuImporter::class)->import(restaurant(), $data, $prune);
}

it('creates the menu the file describes', function (): void {
    $report = importMenu(importable([
        'modifier_groups' => [[
            'name' => 'Size',
            'slug' => 'size',
            'selection_type' => 'single',
            'is_required' => true,
            'modifiers' => [
                ['name' => 'Regular', 'price_delta' => '0.00', 'is_default' => true],
                ['name' => 'Large', 'price_delta' => '2.00'],
            ],
        ]],
        'categories' => [[
            'name' => 'Mains',
            'description' => 'The big ones.',
            'items' => [[
                'name' => 'Chicken Korma',
                'price' => '11.90',
                'aliases' => ['korma', 'the korma'],
                'prep_minutes' => 20,
                'modifier_groups' => ['size'],
            ]],
        ]],
    ]));

    $item = MenuItem::query()->where('slug', 'chicken-korma')->sole();

    expect($item->price)->toBe(1190)
        ->and($item->spoken_aliases)->toBe(['korma', 'the korma'])
        ->and($item->prep_minutes)->toBe(20)
        ->and($item->category->name)->toBe('Mains')
        ->and($item->modifierGroups->pluck('slug')->all())->toBe(['size'])
        ->and(Modifier::query()->where('slug', 'size-large')->sole()->price_delta)->toBe(200)
        ->and($report->count('item', 'created'))->toBe(1)
        ->and($report->count('modifier', 'created'))->toBe(2);
});

describe('running it again', function (): void {
    it('changes nothing when the file has not changed', function (): void {
        importMenu(importable());
        $report = importMenu(importable());

        expect(MenuItem::query()->count())->toBe(1)
            ->and($report->count('item', 'unchanged'))->toBe(1)
            ->and($report->count('item', 'created'))->toBe(0)
            ->and($report->changedAnything())->toBeFalse();
    });

    it('updates only what the corrected file corrected', function (): void {
        importMenu(importable([
            'categories' => [[
                'name' => 'Mains',
                'items' => [
                    ['name' => 'Chicken Korma', 'price' => '11.90'],
                    ['name' => 'Lamb Rogan Josh', 'price' => '13.50'],
                ],
            ]],
        ]));

        $report = importMenu(importable([
            'categories' => [[
                'name' => 'Mains',
                'items' => [
                    ['name' => 'Chicken Korma', 'price' => '12.50'],
                    ['name' => 'Lamb Rogan Josh', 'price' => '13.50'],
                ],
            ]],
        ]));

        expect($report->names('item', 'updated'))->toBe(['Chicken Korma'])
            ->and($report->names('item', 'unchanged'))->toBe(['Lamb Rogan Josh'])
            ->and(MenuItem::query()->where('slug', 'chicken-korma')->sole()->price)->toBe(1250);
    });

    /*
     * A CSV of new prices should be safe to import over a menu whose sizes and
     * extras were set up in the dashboard. Silence in the file is not an
     * instruction to detach them.
     */
    it('leaves modifier groups attached when the file does not mention them', function (): void {
        importMenu(importable([
            'modifier_groups' => [['name' => 'Size', 'slug' => 'size', 'modifiers' => [['name' => 'Large', 'price_delta' => '2.00']]]],
            'categories' => [['name' => 'Mains', 'items' => [
                ['name' => 'Chicken Korma', 'price' => '11.90', 'modifier_groups' => ['size']],
            ]]],
        ]));

        importMenu(importable());

        expect(MenuItem::query()->where('slug', 'chicken-korma')->sole()->modifierGroups)->toHaveCount(1);
    });

    it('detaches them when the file says so explicitly', function (): void {
        importMenu(importable([
            'modifier_groups' => [['name' => 'Size', 'slug' => 'size', 'modifiers' => [['name' => 'Large', 'price_delta' => '2.00']]]],
            'categories' => [['name' => 'Mains', 'items' => [
                ['name' => 'Chicken Korma', 'price' => '11.90', 'modifier_groups' => ['size']],
            ]]],
        ]));

        importMenu(importable([
            'categories' => [['name' => 'Mains', 'items' => [
                ['name' => 'Chicken Korma', 'price' => '11.90', 'modifier_groups' => []],
            ]]],
        ]));

        expect(MenuItem::query()->where('slug', 'chicken-korma')->sole()->modifierGroups)->toHaveCount(0);
    });

    it('does not blank a description the file is silent about', function (): void {
        importMenu(importable([
            'categories' => [['name' => 'Mains', 'items' => [
                ['name' => 'Chicken Korma', 'price' => '11.90', 'description' => 'Mild, creamy, almonds.'],
            ]]],
        ]));

        importMenu(importable());

        expect(MenuItem::query()->where('slug', 'chicken-korma')->sole()->description)
            ->toBe('Mild, creamy, almonds.');
    });
});

describe('prices', function (): void {
    it('reads every way a person writes pounds and pence', function (string $written, int $pence): void {
        importMenu(importable([
            'categories' => [['name' => 'Mains', 'items' => [['name' => 'Korma', 'price' => $written]]]],
        ]));

        expect(MenuItem::query()->where('slug', 'korma')->sole()->price)->toBe($pence);
    })->with([
        ['6.50', 650],
        ['£6.50', 650],
        ['6', 600],
        ['11.9', 1190],
        ['1,200.00', 120000],
    ]);

    it('takes a bare number as pounds, never as pence', function (): void {
        importMenu(importable([
            'categories' => [['name' => 'Mains', 'items' => [['name' => 'Korma', 'price' => 6]]]],
        ]));

        expect(MenuItem::query()->where('slug', 'korma')->sole()->price)->toBe(600);
    });

    it('names the dish when there is no price at all', function (): void {
        expect(fn () => importMenu(importable([
            'categories' => [['name' => 'Mains', 'items' => [['name' => 'Korma']]]],
        ])))->toThrow(MenuImportException::class, 'Korma');
    });

    it('explains the units when a price cannot be read', function (): void {
        expect(fn () => importMenu(importable([
            'categories' => [['name' => 'Mains', 'items' => [['name' => 'Korma', 'price' => 'market price']]]],
        ])))->toThrow(MenuImportException::class, 'pounds and pence');
    });
});

describe('availability', function (): void {
    it('takes yes, Y and TRUE from a spreadsheet', function (mixed $written, bool $expected): void {
        importMenu(importable([
            'categories' => [['name' => 'Mains', 'items' => [
                ['name' => 'Korma', 'price' => '11.90', 'is_available' => $written],
            ]]],
        ]));

        expect(MenuItem::query()->where('slug', 'korma')->sole()->is_available)->toBe($expected);
    })->with([
        ['yes', true], ['Y', true], ['TRUE', true], ['1', true], [true, true],
        ['no', false], ['N', false], ['false', false], ['0', false], [false, false],
    ]);

    /*
     * Withdrawing a dish because its availability cell said "in season" is not
     * a defensible reading of that cell.
     */
    it('treats a cell it cannot read as silence rather than as no', function (): void {
        importMenu(importable([
            'categories' => [['name' => 'Mains', 'items' => [
                ['name' => 'Korma', 'price' => '11.90', 'is_available' => 'in season'],
            ]]],
        ]));

        expect(MenuItem::query()->where('slug', 'korma')->sole()->is_available)->toBeTrue();
    });

    it('stores serving times the way the column stores them', function (): void {
        importMenu(importable([
            'categories' => [[
                'name' => 'Lunch',
                'available_from' => '11:30',
                'available_until' => '15:00',
                'available_days' => [1, 2, 3, 4, 5],
                'items' => [['name' => 'Wrap', 'price' => '7.00']],
            ]],
        ]));

        $category = MenuCategory::query()->where('slug', 'lunch')->sole();

        expect($category->available_from)->toBe('11:30:00')
            ->and($category->available_until)->toBe('15:00:00')
            ->and($category->available_days)->toBe([1, 2, 3, 4, 5]);
    });

    it('rejects an ISO weekday, because Sunday is 0 here', function (): void {
        expect(fn () => importMenu(importable([
            'categories' => [[
                'name' => 'Lunch',
                'available_days' => [1, 2, 3, 4, 5, 6, 7],
                'items' => [['name' => 'Wrap', 'price' => '7.00']],
            ]],
        ])))->toThrow(MenuImportException::class, '0 for Sunday');
    });
});

describe('--prune', function (): void {
    it('takes off the menu what the file no longer mentions', function (): void {
        importMenu(importable([
            'categories' => [['name' => 'Mains', 'items' => [
                ['name' => 'Chicken Korma', 'price' => '11.90'],
                ['name' => 'Lamb Rogan Josh', 'price' => '13.50'],
            ]]],
        ]));

        $report = importMenu(importable(), prune: true);

        expect($report->names('item', 'withdrawn'))->toBe(['Lamb Rogan Josh'])
            ->and(MenuItem::query()->where('slug', 'lamb-rogan-josh')->sole()->is_available)->toBeFalse()
            ->and(MenuItem::query()->where('slug', 'chicken-korma')->sole()->is_available)->toBeTrue();
    });

    /*
     * A dish has order history hanging off it. "We don't do that any more"
     * means it cannot be ordered tonight, not that last Tuesday never happened.
     */
    it('deletes nothing', function (): void {
        importMenu(importable());
        importMenu(importable(['categories' => [['name' => 'Puddings', 'items' => [['name' => 'Kulfi', 'price' => '4.00']]]]]), prune: true);

        expect(MenuItem::query()->count())->toBe(2)
            ->and(MenuCategory::query()->count())->toBe(2);
    });

    it('deactivates a whole section that has gone', function (): void {
        importMenu(importable());
        importMenu(importable(['categories' => [['name' => 'Puddings', 'items' => [['name' => 'Kulfi', 'price' => '4.00']]]]]), prune: true);

        expect(MenuCategory::query()->where('slug', 'mains')->sole()->is_active)->toBeFalse();
    });

    it('reports nothing the second time', function (): void {
        importMenu(importable([
            'categories' => [['name' => 'Mains', 'items' => [
                ['name' => 'Chicken Korma', 'price' => '11.90'],
                ['name' => 'Lamb Rogan Josh', 'price' => '13.50'],
            ]]],
        ]));

        importMenu(importable(), prune: true);
        $report = importMenu(importable(), prune: true);

        expect($report->names('item', 'withdrawn'))->toBe([]);
    });

    it('does nothing at all unless it is asked', function (): void {
        importMenu(importable([
            'categories' => [['name' => 'Mains', 'items' => [
                ['name' => 'Chicken Korma', 'price' => '11.90'],
                ['name' => 'Lamb Rogan Josh', 'price' => '13.50'],
            ]]],
        ]));

        importMenu(importable());

        expect(MenuItem::query()->where('slug', 'lamb-rogan-josh')->sole()->is_available)->toBeTrue();
    });
});

describe('modifier groups', function (): void {
    it('scopes a modifier slug to its group, so two Larges stay two things', function (): void {
        importMenu(importable([
            'modifier_groups' => [
                ['name' => 'Size', 'slug' => 'size', 'modifiers' => [['name' => 'Large', 'price_delta' => '2.00']]],
                ['name' => 'Drink size', 'slug' => 'drink-size', 'modifiers' => [['name' => 'Large', 'price_delta' => '0.80']]],
            ],
        ]));

        expect(Modifier::query()->count())->toBe(2)
            ->and(Modifier::query()->where('slug', 'size-large')->sole()->price_delta)->toBe(200)
            ->and(Modifier::query()->where('slug', 'drink-size-large')->sole()->price_delta)->toBe(80);
    });

    it('lets a dish reference a group defined in the dashboard rather than the file', function (): void {
        $group = ModifierGroup::factory()->for(restaurant())->create(['slug' => 'heat']);

        importMenu(importable([
            'categories' => [['name' => 'Mains', 'items' => [
                ['name' => 'Korma', 'price' => '11.90', 'modifier_groups' => ['heat']],
            ]]],
        ]));

        expect(MenuItem::query()->where('slug', 'korma')->sole()->modifierGroups->pluck('id')->all())
            ->toBe([$group->id]);
    });

    it('names the group it cannot find', function (): void {
        expect(fn () => importMenu(importable([
            'categories' => [['name' => 'Mains', 'items' => [
                ['name' => 'Korma', 'price' => '11.90', 'modifier_groups' => ['spiciness']],
            ]]],
        ])))->toThrow(MenuImportException::class, 'spiciness');
    });
});

/*
 * A menu half-applied because row two hundred had a typo is worse than no
 * import: the agent would spend the evening quoting a menu nobody published.
 */
it('imports the whole file or none of it', function (): void {
    expect(fn () => importMenu(importable([
        'categories' => [['name' => 'Mains', 'items' => [
            ['name' => 'Chicken Korma', 'price' => '11.90'],
            ['name' => 'Lamb Rogan Josh', 'price' => 'ask the chef'],
        ]]],
    ])))->toThrow(MenuImportException::class);

    expect(MenuItem::query()->count())->toBe(0)
        ->and(MenuCategory::query()->count())->toBe(0);
});

it('writes nothing on a dry run', function (): void {
    $report = app(MenuImporter::class)->dryRun(restaurant(), importable());

    expect($report->count('item', 'created'))->toBe(1)
        ->and(MenuItem::query()->count())->toBe(0);
});

/*
 * There is one restaurant today, and the schema is multi-tenant anyway. An
 * importer that reaches across tenants would be discovered on the day a second
 * one arrives, which is the worst possible day to discover it.
 */
it('imports into one restaurant without touching another', function (): void {
    $first = restaurant();
    $second = restaurant(['slug' => 'other-kitchen']);

    app(MenuImporter::class)->import($first, importable());
    app(MenuImporter::class)->import($second, importable());

    expect(MenuItem::query()->where('slug', 'chicken-korma')->count())->toBe(2)
        ->and($first->menuItems()->count())->toBe(1)
        ->and($second->menuItems()->count())->toBe(1);
});
