<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DemoRestaurantSeeder;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| The login the demo seeder creates
|--------------------------------------------------------------------------
|
| A boilerplate has to hand a stranger a working login in the first ten
| minutes, and must not hand one to a stranger who turns up later on a public
| hostname. These tests pin both halves of that.
|
*/

/**
 * The seeder, run the way a deploy runs it.
 *
 * Through `artisan --force` rather than `$this->seed()`, because the
 * production cases below are the interesting ones and `db:seed` stops to ask
 * for confirmation there — which is the prompt a deploy script has already
 * answered by the time this code is reached.
 */
function seedTheRestaurant(): void
{
    test()->artisan('db:seed', [
        '--class' => DemoRestaurantSeeder::class,
        '--force' => true,
    ])->run();
}

function seededOwner(): User
{
    $user = User::query()->where('email', 'owner@embergrill.example')->first();

    expect($user)->not->toBeNull();

    return $user;
}

it('uses the password from the README when nothing else is configured', function (): void {
    config()->set('restaurantline.admin_password', null);

    seedTheRestaurant();

    expect(Hash::check(DemoRestaurantSeeder::DEFAULT_PASSWORD, seededOwner()->password))->toBeTrue();
});

it('uses ADMIN_PASSWORD when there is one', function (): void {
    config()->set('restaurantline.admin_password', 'a-password-nobody-published');

    seedTheRestaurant();

    expect(Hash::check('a-password-nobody-published', seededOwner()->password))->toBeTrue()
        ->and(Hash::check(DemoRestaurantSeeder::DEFAULT_PASSWORD, seededOwner()->password))->toBeFalse();
});

it('rotates the password on a re-seed rather than keeping the first one', function (): void {
    config()->set('restaurantline.admin_password', null);
    seedTheRestaurant();

    config()->set('restaurantline.admin_password', 'the-rotated-one');
    seedTheRestaurant();

    expect(User::query()->where('email', 'owner@embergrill.example')->count())->toBe(1)
        ->and(Hash::check('the-rotated-one', seededOwner()->password))->toBeTrue();
});

it('refuses to create a login with the published password in production', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config()->set('restaurantline.admin_password', null);

    expect(fn () => seedTheRestaurant())
        ->toThrow(RuntimeException::class, 'published in this repository');

    expect(User::query()->where('email', 'owner@embergrill.example')->exists())->toBeFalse();
});

it('is content in production once ADMIN_PASSWORD is set', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config()->set('restaurantline.admin_password', 'set-by-the-person-deploying-it');

    seedTheRestaurant();

    expect(Hash::check('set-by-the-person-deploying-it', seededOwner()->password))->toBeTrue();
});
