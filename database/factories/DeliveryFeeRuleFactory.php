<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DeliveryFeeRule;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryFeeRule>
 */
class DeliveryFeeRuleFactory extends Factory
{
    protected $model = DeliveryFeeRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'up_to_metres' => 2000,
            'fee' => 199,
            'free_over_subtotal' => null,
            'sort_order' => 0,
        ];
    }

    public function band(?int $upToMetres, int $fee, ?int $freeOver = null, int $sortOrder = 0): self
    {
        return $this->state(fn (): array => [
            'up_to_metres' => $upToMetres,
            'fee' => $fee,
            'free_over_subtotal' => $freeOver,
            'sort_order' => $sortOrder,
        ]);
    }
}
