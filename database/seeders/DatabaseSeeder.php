<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Everything a fresh `docker compose up` needs to be a working demo: one
 * restaurant, its hours and delivery bands, a full menu, and a day of calls.
 *
 * Every seeder here is idempotent, so running this a second time updates rather
 * than duplicates — including DemoOrdersSeeder, which rewrites the same day of
 * calls rather than adding another one.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            DemoRestaurantSeeder::class,
            SampleMenuSeeder::class,
            DemoOrdersSeeder::class,
        ]);
    }
}
