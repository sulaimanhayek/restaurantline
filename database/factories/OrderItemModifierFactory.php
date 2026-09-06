<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ModifierKind;
use App\Models\Modifier;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrderItemModifier>
 */
class OrderItemModifierFactory extends Factory
{
    protected $model = OrderItemModifier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'modifier_id' => null,
            'modifier_group_id' => null,
            'group_name' => 'Extras',
            'name' => Str::title($this->faker->words(2, true)),
            'kind' => ModifierKind::Addon,
            'price_delta' => $this->faker->randomElement([0, 50, 100, 150]),
            'quantity' => 1,
            'sort_order' => 0,
        ];
    }

    public function forModifier(Modifier $modifier): self
    {
        return $this->state(fn (): array => [
            'modifier_id' => $modifier->id,
            'modifier_group_id' => $modifier->modifier_group_id,
            'group_name' => $modifier->group->name,
            'name' => $modifier->name,
            'kind' => $modifier->kind,
            'price_delta' => $modifier->price_delta,
        ]);
    }

    public function removal(string $name = 'Onions'): self
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'kind' => ModifierKind::Removal,
            'price_delta' => 0,
        ]);
    }
}
