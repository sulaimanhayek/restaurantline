<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = $this->faker->numberBetween(500, 1600);
        $quantity = $this->faker->numberBetween(1, 3);

        return [
            'order_id' => Order::factory(),
            'menu_item_id' => null,
            // Snapshot: the name and price as they were when the call happened.
            'name' => Str::title($this->faker->words(2, true)),
            'description' => null,
            'sku' => null,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_total' => $unitPrice * $quantity,
            'modifiers_snapshot' => [],
            'notes' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * Copy the live menu row into the snapshot, the way the order builder does.
     */
    public function forMenuItem(MenuItem $item, int $quantity = 1): self
    {
        return $this->state(fn (): array => [
            'menu_item_id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'sku' => $item->sku,
            'unit_price' => $item->price,
            'quantity' => $quantity,
            'line_total' => $item->price * $quantity,
        ]);
    }
}
