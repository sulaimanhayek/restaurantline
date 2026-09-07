<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ModifierKind;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Modifier>
 */
class ModifierFactory extends Factory
{
    protected $model = Modifier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title($this->faker->unique()->words(2, true));

        return [
            'restaurant_id' => Restaurant::factory(),
            'modifier_group_id' => ModifierGroup::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'price_delta' => 0,
            'kind' => ModifierKind::Option,
            'spoken_aliases' => [],
            'is_available' => true,
            'is_default' => false,
            'sort_order' => 0,
        ];
    }

    public function addon(int $priceDelta = 100): self
    {
        return $this->state(fn (): array => [
            'kind' => ModifierKind::Addon,
            'price_delta' => $priceDelta,
        ]);
    }

    /**
     * A removal is a real row, not a free-text note — see DECISIONS #0004.
     */
    public function removal(): self
    {
        return $this->state(fn (): array => [
            'kind' => ModifierKind::Removal,
            'price_delta' => 0,
        ]);
    }

    public function swap(int $priceDelta = 0): self
    {
        return $this->state(fn (): array => [
            'kind' => ModifierKind::Swap,
            'price_delta' => $priceDelta,
        ]);
    }

    public function unavailable(): self
    {
        return $this->state(fn (): array => ['is_available' => false]);
    }

    public function defaultChoice(): self
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
