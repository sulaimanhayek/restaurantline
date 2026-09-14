<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Restaurant>
 */
class RestaurantFactory extends Factory
{
    protected $model = Restaurant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->company().' Kitchen';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'timezone' => 'Europe/London',
            'currency' => 'GBP',
            'phone_number' => '+4420'.$this->faker->numerify('########'),
            'transfer_phone_number' => '+4477'.$this->faker->numerify('########'),
            'email' => $this->faker->companyEmail(),
            'address_line_1' => $this->faker->buildingNumber().' '.$this->faker->streetName(),
            'city' => 'London',
            'postcode' => 'E1 6AN',
            'country' => 'GB',
            // Central London, so the fake geocoder's fixtures sit nearby.
            'latitude' => 51.5155,
            'longitude' => -0.0722,
            'delivery_radius_metres' => 5000,
            'minimum_order_value' => 1500,
            'base_delivery_fee' => 299,
            'collection_prep_minutes' => 20,
            'delivery_prep_minutes' => 45,
            'is_accepting_orders' => true,
            // Both on, because both on is the case that makes the agent ask —
            // and a default that skips the question would let the asking path
            // rot untested.
            'accepts_card_link' => true,
            'accepts_cash' => true,
            'payment_link_ttl_minutes' => 60,
            'agent_tone_of_voice' => 'Warm, brisk and efficient. Never chatty — callers are hungry.',
        ];
    }

    public function notAcceptingOrders(): self
    {
        return $this->state(fn (): array => ['is_accepting_orders' => false]);
    }

    /** Cash only: the agent should never mention a payment link. */
    public function cashOnly(): self
    {
        return $this->state(fn (): array => [
            'accepts_card_link' => false,
            'accepts_cash' => true,
        ]);
    }

    /** Card only: the agent should never offer to take cash at the door. */
    public function cardOnly(): self
    {
        return $this->state(fn (): array => [
            'accepts_card_link' => true,
            'accepts_cash' => false,
        ]);
    }

    public function provisioned(): self
    {
        return $this->state(fn (): array => [
            'elevenlabs_agent_id' => 'agent_'.Str::lower(Str::random(24)),
            'elevenlabs_tool_ids' => ['menu_search' => 'tool_'.Str::lower(Str::random(20))],
            'provisioned_at' => now(),
        ]);
    }
}
