<?php

declare(strict_types=1);

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every test gets a fresh PostgreSQL schema. Slower than SQLite in memory, and
| worth it: the menu matcher and the availability queries are PostgreSQL-shaped,
| and a suite that green-lights a query the production database would reject is
| the most expensive kind of passing test.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * The restaurant every test hangs off.
 *
 * Returns the one already in the database if there is one, so a test that
 * needs three menu items does not silently create three restaurants and then
 * fail a scoped query for reasons that take an hour to find.
 *
 * @param  array<string, mixed>  $attributes
 */
function restaurant(array $attributes = []): Restaurant
{
    if ($attributes !== []) {
        return Restaurant::factory()->create($attributes);
    }

    return Restaurant::query()->first()
        ?? Restaurant::factory()->create();
}
