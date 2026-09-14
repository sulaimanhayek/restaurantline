<?php

declare(strict_types=1);

use App\Models\MenuItem;

/**
 * `kitchenline:import-menu`, which is the second thing anybody runs.
 *
 * The command itself is thin — `MenuFile` reads, `MenuImporter` writes — so
 * what is worth testing here is everything that protects a person from the two
 * commands: that a dry run writes nothing and shows the prices as money, that
 * `--prune` asks first, and that a bad file produces a sentence rather than a
 * stack trace.
 */
function menuFixture(string $name, string $contents): string
{
    $path = sys_get_temp_dir().'/'.uniqid('import-', true).'-'.$name;
    file_put_contents($path, $contents);

    return $path;
}

function csvFixture(string $contents = "category,name,price\nMains,Chicken Korma,11.90"): string
{
    return menuFixture('menu.csv', $contents);
}

beforeEach(function (): void {
    restaurant();
});

it('imports a CSV', function (): void {
    $this->artisan('kitchenline:import-menu', ['file' => csvFixture()])
        ->expectsOutputToContain('Menu imported')
        ->assertSuccessful();

    expect(MenuItem::query()->where('slug', 'chicken-korma')->sole()->price)->toBe(1190);
});

it('imports a JSON file', function (): void {
    $path = menuFixture('menu.json', (string) json_encode([
        'categories' => [['name' => 'Mains', 'items' => [['name' => 'Chicken Korma', 'price' => '11.90']]]],
    ]));

    $this->artisan('kitchenline:import-menu', ['file' => $path])->assertSuccessful();

    expect(MenuItem::query()->count())->toBe(1);
});

describe('--dry-run', function (): void {
    it('writes nothing', function (): void {
        $this->artisan('kitchenline:import-menu', ['file' => csvFixture(), '--dry-run' => true])
            ->assertSuccessful();

        expect(MenuItem::query()->count())->toBe(0);
    });

    /*
     * The whole value of --dry-run. A price column misread by a factor of a
     * hundred does not announce itself; seeing "£11.90" next to the dish is the
     * only way anybody catches it before a customer does.
     */
    it('prints every price the way a customer would hear it', function (): void {
        $this->artisan('kitchenline:import-menu', ['file' => csvFixture(), '--dry-run' => true])
            ->expectsOutputToContain('£11.90')
            ->assertSuccessful();
    });

    it('says when the menu already matches the file', function (): void {
        $this->artisan('kitchenline:import-menu', ['file' => csvFixture()])->assertSuccessful();

        $this->artisan('kitchenline:import-menu', ['file' => csvFixture(), '--dry-run' => true])
            ->expectsOutputToContain('already matches')
            ->assertSuccessful();
    });

    it('validates as thoroughly as the real thing would', function (): void {
        $path = csvFixture("category,name,price\nMains,Korma,ask the chef");

        $this->artisan('kitchenline:import-menu', ['file' => $path, '--dry-run' => true])
            ->expectsOutputToContain('pounds and pence')
            ->assertFailed();
    });
});

describe('--prune', function (): void {
    it('asks before taking anything off the menu', function (): void {
        $this->artisan('kitchenline:import-menu', ['file' => csvFixture()])->assertSuccessful();

        $this->artisan('kitchenline:import-menu', [
            'file' => csvFixture("category,name,price\nPuddings,Kulfi,4.00"),
            '--prune' => true,
        ])
            ->expectsConfirmation('Go ahead?', 'no')
            ->assertFailed();

        expect(MenuItem::query()->where('slug', 'chicken-korma')->sole()->is_available)->toBeTrue();
    });

    it('names what it withdrew when told to go ahead', function (): void {
        $this->artisan('kitchenline:import-menu', ['file' => csvFixture()])->assertSuccessful();

        $this->artisan('kitchenline:import-menu', [
            'file' => csvFixture("category,name,price\nPuddings,Kulfi,4.00"),
            '--prune' => true,
        ])
            ->expectsConfirmation('Go ahead?', 'yes')
            ->expectsOutputToContain('Chicken Korma')
            ->assertSuccessful();

        expect(MenuItem::query()->where('slug', 'chicken-korma')->sole()->is_available)->toBeFalse();
    });

    it('does not ask on a dry run, because there is nothing to agree to', function (): void {
        $this->artisan('kitchenline:import-menu', [
            'file' => csvFixture(),
            '--prune' => true,
            '--dry-run' => true,
        ])->assertSuccessful();
    });
});

describe('the ways it refuses', function (): void {
    it('explains a file it cannot find', function (): void {
        $this->artisan('kitchenline:import-menu', ['file' => '/tmp/nope.json'])
            ->expectsOutputToContain('no such file')
            ->assertFailed();
    });

    it('explains a format it does not read', function (): void {
        $this->artisan('kitchenline:import-menu', ['file' => menuFixture('menu.xlsx', 'nonsense')])
            ->expectsOutputToContain('.json or .csv')
            ->assertFailed();
    });

    /*
     * The one thing somebody will want to know after a failed import, and the
     * one thing they would otherwise spend an hour checking by hand.
     */
    it('says that nothing was written when a row is bad', function (): void {
        $path = csvFixture("category,name,price\nMains,Korma,11.90\nMains,Bhaji,ask the chef");

        $this->artisan('kitchenline:import-menu', ['file' => $path])
            ->expectsOutputToContain('Nothing was imported')
            ->assertFailed();

        expect(MenuItem::query()->count())->toBe(0);
    });

    it('names a restaurant slug it does not have', function (): void {
        $this->artisan('kitchenline:import-menu', [
            'file' => csvFixture(),
            '--restaurant' => 'not-a-restaurant',
        ])
            ->expectsOutputToContain('not-a-restaurant')
            ->assertFailed();
    });
});

it('imports into the restaurant it is pointed at', function (): void {
    $other = restaurant(['slug' => 'other-kitchen']);

    $this->artisan('kitchenline:import-menu', [
        'file' => csvFixture(),
        '--restaurant' => 'other-kitchen',
    ])->assertSuccessful();

    expect($other->menuItems()->count())->toBe(1)
        ->and(MenuItem::query()->count())->toBe(1);
});
