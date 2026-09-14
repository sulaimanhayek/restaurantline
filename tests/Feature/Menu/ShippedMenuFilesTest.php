<?php

declare(strict_types=1);

use App\Models\MenuItem;
use Database\Seeders\DatabaseSeeder;

/**
 * The two example menus, imported the way a curious forker imports them.
 *
 * `database/menus/example.json` and `example.csv` are the first two files
 * anybody opens after the seed, and the first two they run a command against.
 * Everything else in `ImportMenuCommandTest` uses fixtures written by the test,
 * which is right for testing the importer and useless for testing the examples:
 * a fixture cannot go stale and these can.
 *
 * Each runs on its own freshly seeded database, and not one after the other,
 * because the interesting property is that either file works on its own. A CSV
 * cannot define a modifier group, only reference one, so `example.csv` may only
 * name groups that `migrate --seed` has already created — and the way that rule
 * gets broken is by adding a group to the JSON, using it in the CSV, and never
 * running the CSV against anything but a database the JSON had already been
 * imported into.
 */
beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('imports a shipped example menu onto a freshly seeded restaurant', function (string $file): void {
    $this->artisan('kitchenline:import-menu', ['file' => base_path('database/menus/'.$file)])
        ->expectsOutputToContain('Menu imported')
        ->assertSuccessful();

    expect(MenuItem::query()->where('slug', 'chicken-korma')->sole()->price)->toBe(1190);
})->with(['example.json', 'example.csv']);

/**
 * Importing twice is the documented loop — import, look at it, fix the file,
 * import again — so the second run has to be a no-op rather than a second menu.
 */
it('imports a shipped example menu twice without duplicating it', function (string $file): void {
    $path = base_path('database/menus/'.$file);

    $this->artisan('kitchenline:import-menu', ['file' => $path])->assertSuccessful();
    $after = MenuItem::query()->count();

    $this->artisan('kitchenline:import-menu', ['file' => $path])->assertSuccessful();

    expect(MenuItem::query()->count())->toBe($after);
})->with(['example.json', 'example.csv']);

/**
 * The reference that broke: `spice-level` is a group the seeder creates, and
 * the CSV names it by slug. If it stops resolving, the dish silently loses the
 * question the agent asks about it.
 */
it('resolves a modifier group the CSV only references', function (): void {
    $this->artisan('kitchenline:import-menu', ['file' => base_path('database/menus/example.csv')])
        ->assertSuccessful();

    expect(
        MenuItem::query()->where('slug', 'chicken-korma')->sole()
            ->modifierGroups()->pluck('slug')->all(),
    )->toContain('spice-level');
});
