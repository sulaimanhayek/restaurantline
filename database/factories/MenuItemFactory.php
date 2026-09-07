<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title($this->faker->unique()->words(2, true));

        return [
            'restaurant_id' => Restaurant::factory(),
            'menu_category_id' => MenuCategory::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $this->faker->sentence(),
            'price' => $this->faker->numberBetween(400, 1800),
            'sku' => Str::upper(Str::random(6)),
            'is_available' => true,
            'spoken_aliases' => [],
            'sort_order' => 0,
            'prep_minutes' => null,
        ];
    }

    public function unavailable(): self
    {
        return $this->state(fn (): array => ['is_available' => false]);
    }

    /**
     * @param  list<string>  $aliases
     */
    public function withAliases(array $aliases): self
    {
        return $this->state(fn (): array => ['spoken_aliases' => $aliases]);
    }

    public function pricedAt(int $minorUnits): self
    {
        return $this->state(fn (): array => ['price' => $minorUnits]);
    }
}
