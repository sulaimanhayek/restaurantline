<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ModifierGroupSelectionType;
use App\Models\ModifierGroup;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ModifierGroup>
 */
class ModifierGroupFactory extends Factory
{
    protected $model = ModifierGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title($this->faker->unique()->words(2, true));

        return [
            'restaurant_id' => Restaurant::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'prompt' => null,
            'selection_type' => ModifierGroupSelectionType::Single,
            'min_selections' => 0,
            'max_selections' => 1,
            'is_required' => false,
            'sort_order' => 0,
        ];
    }

    /**
     * Pick exactly one — the size case.
     */
    public function requiredSingle(): self
    {
        return $this->state(fn (): array => [
            'selection_type' => ModifierGroupSelectionType::Single,
            'min_selections' => 1,
            'max_selections' => 1,
            'is_required' => true,
        ]);
    }

    /**
     * Pick any number — the add-ons case.
     */
    public function multi(?int $max = null): self
    {
        return $this->state(fn (): array => [
            'selection_type' => ModifierGroupSelectionType::Multi,
            'min_selections' => 0,
            'max_selections' => $max,
            'is_required' => false,
        ]);
    }
}
