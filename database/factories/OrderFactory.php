<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FulfilmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = $this->faker->numberBetween(1500, 4500);

        return [
            'restaurant_id' => Restaurant::factory(),
            'customer_id' => Customer::factory(),
            'address_id' => null,
            'order_number' => (string) $this->faker->unique()->numberBetween(1000, 9999),
            'fulfilment_type' => FulfilmentType::Collection,
            'status' => OrderStatus::Draft,
            'payment_status' => PaymentStatus::Unpaid,
            'source' => OrderSource::Voice,
            'subtotal' => $subtotal,
            'delivery_fee' => 0,
            'total' => $subtotal,
            'requested_at' => null,
            'estimated_ready_at' => now()->addMinutes(20),
            'estimated_minutes' => 20,
            'notes' => null,
            'elevenlabs_conversation_id' => 'conv_'.Str::lower(Str::random(24)),
            'conversation_id' => null,
        ];
    }

    /**
     * Delivery orders carry a verified address — an unverified one is rejected
     * at the endpoint, so the happy-path factory state must mirror that.
     */
    public function delivery(int $deliveryFee = 299): self
    {
        return $this->state(function (array $attributes) use ($deliveryFee): array {
            $subtotal = (int) ($attributes['subtotal'] ?? 2000);

            return [
                'fulfilment_type' => FulfilmentType::Delivery,
                'address_id' => Address::factory()->verified(),
                'delivery_fee' => $deliveryFee,
                'total' => $subtotal + $deliveryFee,
                'estimated_minutes' => 45,
                'estimated_ready_at' => now()->addMinutes(45),
            ];
        });
    }

    public function collection(): self
    {
        return $this->state(fn (): array => [
            'fulfilment_type' => FulfilmentType::Collection,
            'address_id' => null,
            'delivery_fee' => 0,
        ]);
    }

    /**
     * Read back to the caller but not yet agreed to.
     */
    public function confirming(): self
    {
        return $this->state(fn (): array => ['status' => OrderStatus::Confirming]);
    }

    public function confirmed(): self
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    public function withStatus(OrderStatus $status): self
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'confirmed_at' => $status->isCommitted() ? now() : null,
        ]);
    }

    public function cancelled(string $reason = 'Caller changed their mind'): self
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);
    }

    public function paid(): self
    {
        return $this->state(fn (): array => ['payment_status' => PaymentStatus::Paid]);
    }

    public function fromDashboard(): self
    {
        return $this->state(fn (): array => [
            'source' => OrderSource::Dashboard,
            'elevenlabs_conversation_id' => null,
        ]);
    }
}
