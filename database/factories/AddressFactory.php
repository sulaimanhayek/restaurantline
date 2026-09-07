<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GeocodeProvider;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    protected $model = Address::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = $this->faker->buildingNumber();
        $street = $this->faker->streetName();

        return [
            'restaurant_id' => Restaurant::factory(),
            'customer_id' => Customer::factory(),
            // What the caller actually said, warts and all. Kept forever.
            'raw_spoken_text' => "{$number} {$street}, London",
            'formatted_address' => "{$number} {$street}, London, E2 8AA, UK",
            'line_1' => "{$number} {$street}",
            'line_2' => null,
            'city' => 'London',
            'postcode' => 'E2 8AA',
            'country' => 'GB',
            'latitude' => 51.5290,
            'longitude' => -0.0620,
            'geocode_confidence' => 0.900,
            'geocode_provider' => GeocodeProvider::Fake,
            'place_id' => null,
            'verified_at' => null,
            'distance_metres' => 1800,
            'is_default' => false,
        ];
    }

    /**
     * Confirmed aloud by the caller. Delivery orders require this.
     */
    public function verified(): self
    {
        return $this->state(fn (): array => ['verified_at' => now()]);
    }

    public function unverified(): self
    {
        return $this->state(fn (): array => ['verified_at' => null]);
    }

    public function atDistance(int $metres): self
    {
        return $this->state(fn (): array => ['distance_metres' => $metres]);
    }

    /**
     * The caller mumbled and the geocoder guessed. Worth a read-back.
     */
    public function lowConfidence(): self
    {
        return $this->state(fn (): array => ['geocode_confidence' => 0.320]);
    }
}
