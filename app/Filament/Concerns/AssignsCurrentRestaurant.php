<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\Restaurant;

/**
 * Stamps the tenant onto records created from the dashboard.
 *
 * The counterpart to ScopesToCurrentRestaurant: that one keeps rows from
 * another restaurant out of view, this one keeps new rows from being written
 * without an owner. A `restaurant_id` left to the database would be a NOT NULL
 * violation surfacing as a 500 on someone's first attempt to add a dish.
 */
trait AssignsCurrentRestaurant
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['restaurant_id'] = Restaurant::current()->id;

        return $data;
    }
}
