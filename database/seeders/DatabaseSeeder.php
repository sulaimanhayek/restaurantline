<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Everything a fresh `docker compose up` needs to be a working demo: one
 * restaurant, its hours and delivery bands, a full menu, and a day of calls.
 *
 * Each seeder is idempotent, so running this a second time updates rather than
 * duplicates — except DemoOrdersSeeder, which appends a new day of traffic.
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
