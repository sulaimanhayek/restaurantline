<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Horizon\Horizon;

/*
|--------------------------------------------------------------------------
| Who can read the queue
|--------------------------------------------------------------------------
|
| Horizon's dashboard lists the payload of every queued job, and in this
| application those payloads are orders: a name, a telephone number and a
| delivery address per job. It is therefore a customer data page wearing the
| costume of an ops tool, and these tests treat it as one.
|
| The environment-forcing below is the whole point rather than set dressing.
| Horizon's default check is `app()->environment('local')`, so a suite that
| only ever runs under `testing` would pass against no gate at all.
|
*/

it('refuses the queue dashboard to anyone who is not logged in', function (): void {
    $this->get('/horizon')->assertForbidden();
});

it('still refuses it on a laptop, where Horizon would let anybody in', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');

    expect(app()->environment('local'))->toBeTrue()
        ->and($this->get('/horizon')->status())->toBe(403);
});

it('lets a signed-in user through', function (): void {
    $user = User::factory()->create(['restaurant_id' => restaurant()->id]);

    $this->actingAs($user)->get('/horizon')->assertSuccessful();
});

it('asks nothing of the request but a user', function (): void {
    expect(Horizon::check(request()))->toBeFalse();

    $this->actingAs(User::factory()->create(['restaurant_id' => restaurant()->id]));

    expect(Horizon::check(request()->setUserResolver(fn () => auth()->user())))->toBeTrue();
});
