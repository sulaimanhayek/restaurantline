<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OpeningHour;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpeningHour>
 */
class OpeningHourFactory extends Factory
{
    protected $model = OpeningHour::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'day_of_week' => $this->faker->numberBetween(0, 6),
            'opens_at' => '12:00:00',
            'closes_at' => '22:00:00',
            'closes_next_day' => false,
            'label' => null,
        ];
    }

    public function forDay(int $dayOfWeek): self
    {
        return $this->state(fn (): array => ['day_of_week' => $dayOfWeek]);
    }

    public function lunch(): self
    {
        return $this->state(fn (): array => [
            'opens_at' => '12:00:00',
            'closes_at' => '15:00:00',
            'label' => 'Lunch',
        ]);
    }

    public function dinner(): self
    {
        return $this->state(fn (): array => [
            'opens_at' => '17:00:00',
            'closes_at' => '22:30:00',
            'label' => 'Dinner',
        ]);
    }

    /**
     * A window running past midnight — the case that breaks naive time
     * comparisons, and therefore the one worth having a factory state for.
     */
    public function lateNight(): self
    {
        return $this->state(fn (): array => [
            'opens_at' => '22:00:00',
            'closes_at' => '02:00:00',
            'closes_next_day' => true,
            'label' => 'Late',
        ]);
    }
}
