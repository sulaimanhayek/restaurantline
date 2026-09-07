<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MenuCategory;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MenuCategory>
 */
class MenuCategoryFactory extends Factory
{
    protected $model = MenuCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->randomElement([
            'Starters', 'Mains', 'Burgers', 'Sides', 'Desserts', 'Drinks', 'Wraps',
        ]).' '.Str::upper(Str::random(3));

        return [
            'restaurant_id' => Restaurant::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => null,
            'sort_order' => 0,
            'is_active' => true,
            'available_from' => null,
            'available_until' => null,
            'available_days' => null,
        ];
    }

    /**
     * Served only between the given local times — the lunch-menu case.
     */
    public function availableBetween(string $from, string $until): self
    {
        return $this->state(fn (): array => [
            'available_from' => $from,
            'available_until' => $until,
        ]);
    }

    /**
     * @param  list<int>  $days  Weekday numbers, 0 = Sunday
     */
    public function onDays(array $days): self
    {
        return $this->state(fn (): array => ['available_days' => $days]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
