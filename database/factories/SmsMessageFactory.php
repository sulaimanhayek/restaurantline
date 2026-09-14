<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SmsKind;
use App\Enums\SmsStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\SmsMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsMessage>
 */
class SmsMessageFactory extends Factory
{
    protected $model = SmsMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'order_id' => null,
            'customer_id' => null,
            'to_number' => '+4477009'.fake()->numberBetween(10000, 99999),
            'kind' => SmsKind::OrderConfirmation,
            'status' => SmsStatus::Sent,
            'body' => 'Thanks! Your order A-1234 will be ready in about 25 minutes.',
            'provider' => 'log',
            'provider_message_id' => 'log_'.fake()->lexify('????????????????????????'),
            'error' => null,
            'sent_at' => now(),
        ];
    }

    public function forOrder(Order $order): self
    {
        return $this->state(fn (): array => [
            'restaurant_id' => $order->restaurant_id,
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
        ]);
    }

    public function forCustomer(Customer $customer): self
    {
        return $this->state(fn (): array => [
            'restaurant_id' => $customer->restaurant_id,
            'customer_id' => $customer->id,
            'to_number' => $customer->phone_number,
        ]);
    }

    public function failed(string $error = 'The To number is not a valid phone number.'): self
    {
        return $this->state(fn (): array => [
            'status' => SmsStatus::Failed,
            'provider_message_id' => null,
            'error' => $error,
            'sent_at' => null,
        ]);
    }

    public function queued(): self
    {
        return $this->state(fn (): array => [
            'status' => SmsStatus::Queued,
            'provider' => null,
            'provider_message_id' => null,
            'sent_at' => null,
        ]);
    }

    public function paymentLink(): self
    {
        return $this->state(fn (): array => [
            'kind' => SmsKind::PaymentLink,
            'body' => 'Pay for order A-1234: https://pay.example/abc123',
        ]);
    }
}
