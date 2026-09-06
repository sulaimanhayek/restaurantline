<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OpeningHourOverride;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpeningHourOverride>
 */
class OpeningHourOverrideFactory extends Factory
{
    protected $model = OpeningHourOverride::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'date' => now()->addDays(7)->toDateString(),
            'is_closed' => true,
            'opens_at' => null,
            'closes_at' => null,
            'closes_next_day' => false,
            'reason' => 'Bank holiday',
        ];
    }

    public function withHours(string $opensAt, string $closesAt): self
    {
        return $this->state(fn (): array => [
            'is_closed' => false,
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
        ]);
    }
}
