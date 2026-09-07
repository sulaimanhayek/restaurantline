<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            // E.164, because that is what Twilio hands the agent.
            'phone_number' => '+4477'.$this->faker->unique()->numerify('########'),
            'name' => $this->faker->name(),
            'notes' => null,
            'order_count' => 0,
            'first_ordered_at' => null,
            'last_ordered_at' => null,
        ];
    }

    public function returning(int $orders = 5): self
    {
        return $this->state(fn (): array => [
            'order_count' => $orders,
            'first_ordered_at' => now()->subMonths(6),
            'last_ordered_at' => now()->subDays(9),
        ]);
    }
}
