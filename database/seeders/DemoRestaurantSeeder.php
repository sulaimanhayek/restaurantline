<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DeliveryFeeRule;
use App\Models\OpeningHour;
use App\Models\OpeningHourOverride;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The single restaurant a fresh clone comes up with.
 *
 * Ember Grill is a fictional flame-grilled chicken shop in east London. It is
 * deliberately an awkward one: split lunch and dinner shifts, a Friday night
 * that runs past midnight, banded delivery fees and a weekday-only lunch menu.
 * A demo that only models "open 12 to 10, flat £3 delivery" hides most of the
 * bugs you will actually hit on a real client's line.
 */
class DemoRestaurantSeeder extends Seeder
{
    /**
     * The password this seeder uses when `ADMIN_PASSWORD` is unset.
     *
     * Published in the README, because a demo login nobody can find is not a
     * demo login. Which is exactly why `seedAdminUser` will not fall back to
     * it in production.
     */
    public const DEFAULT_PASSWORD = 'password';

    public function run(): void
    {
        $restaurant = Restaurant::query()->updateOrCreate(
            ['slug' => 'ember-grill'],
            [
                'name' => 'Ember Grill',
                'timezone' => 'Europe/London',
                'currency' => 'GBP',
                'phone_number' => '+442071234567',
                // Where the agent transfers a caller who asks for a human.
                'transfer_phone_number' => '+442071234568',
                'email' => 'hello@embergrill.example',
                'address_line_1' => '118 Brick Lane',
                'address_line_2' => null,
                'city' => 'London',
                'postcode' => 'E1 6RL',
                'country' => 'GB',
                'latitude' => 51.5218,
                'longitude' => -0.0715,
                'delivery_radius_metres' => 5000,
                'minimum_order_value' => 1500,
                'base_delivery_fee' => 299,
                'collection_prep_minutes' => 20,
                'delivery_prep_minutes' => 45,
                'is_accepting_orders' => true,
                'agent_tone_of_voice' => <<<'TONE'
                    Warm, quick and unfussy — the voice of someone who has taken
                    a thousand orders and is glad you called. Short sentences.
                    Never upsell twice. If the caller sounds rushed, match them.
                    TONE,
                'agent_greeting' => 'Ember Grill, this is the order line — is it delivery or collection tonight?',
            ],
        );

        $this->seedOpeningHours($restaurant);
        $this->seedDeliveryFees($restaurant);
        $this->seedAdminUser($restaurant);
    }

    /**
     * Split shifts on weekdays, straight through at the weekend, and a Friday
     * and Saturday that close at half past midnight.
     */
    private function seedOpeningHours(Restaurant $restaurant): void
    {
        $restaurant->openingHours()->delete();

        /** @var list<array{int, string, string, bool, string|null}> $shifts */
        $shifts = [
            // Sunday: one straight shift.
            [0, '12:00:00', '22:00:00', false, null],
            // Monday closed — no rows at all for day 1.
            [2, '12:00:00', '15:00:00', false, 'Lunch'],
            [2, '17:00:00', '22:30:00', false, 'Dinner'],
            [3, '12:00:00', '15:00:00', false, 'Lunch'],
            [3, '17:00:00', '22:30:00', false, 'Dinner'],
            [4, '12:00:00', '15:00:00', false, 'Lunch'],
            [4, '17:00:00', '22:30:00', false, 'Dinner'],
            // Friday and Saturday run past midnight.
            [5, '12:00:00', '00:30:00', true, null],
            [6, '12:00:00', '00:30:00', true, null],
        ];

        foreach ($shifts as [$day, $opens, $closes, $nextDay, $label]) {
            OpeningHour::query()->create([
                'restaurant_id' => $restaurant->id,
                'day_of_week' => $day,
                'opens_at' => $opens,
                'closes_at' => $closes,
                'closes_next_day' => $nextDay,
                'label' => $label,
            ]);
        }

        // One closure and one shortened day, so the override path has data.
        OpeningHourOverride::query()->updateOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'date' => $restaurant->now()->addDays(14)->toDateString(),
                'opens_at' => null,
            ],
            [
                'is_closed' => true,
                'closes_at' => null,
                'closes_next_day' => false,
                'reason' => 'Staff party',
            ],
        );

        OpeningHourOverride::query()->updateOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'date' => $restaurant->now()->addDays(21)->toDateString(),
                'opens_at' => '17:00:00',
            ],
            [
                'is_closed' => false,
                'closes_at' => '21:00:00',
                'closes_next_day' => false,
                'reason' => 'Deep clean — opening late',
            ],
        );
    }

    /**
     * Distance bands, cheapest first. The last row has a null `up_to_metres`,
     * which makes it the catch-all for anything inside the delivery radius.
     */
    private function seedDeliveryFees(Restaurant $restaurant): void
    {
        $restaurant->deliveryFeeRules()->delete();

        /** @var list<array{int|null, int, int|null, int}> $bands */
        $bands = [
            [1500, 199, 3000, 0],
            [3000, 299, 4000, 1],
            [null, 449, 5000, 2],
        ];

        foreach ($bands as [$upTo, $fee, $freeOver, $sort]) {
            DeliveryFeeRule::query()->create([
                'restaurant_id' => $restaurant->id,
                'up_to_metres' => $upTo,
                'fee' => $fee,
                'free_over_subtotal' => $freeOver,
                'sort_order' => $sort,
            ]);
        }
    }

    /**
     * A dashboard login, with a password that depends on where this is running.
     *
     * The address and the fallback password are both in the README, which is
     * the point on a laptop: the first hour with this repo should not include
     * a detour through `tinker` to get into the dashboard. It stops being the
     * point the moment the login page is reachable by somebody else, so
     * `ADMIN_PASSWORD` overrides it and production refuses the fallback
     * outright — the same line `AuthenticateAgent` draws around the example
     * agent token, for the same reason.
     *
     * Re-seeding rewrites the password, so setting `ADMIN_PASSWORD` and
     * running `db:seed` again is how you rotate it.
     */
    private function seedAdminUser(Restaurant $restaurant): void
    {
        User::query()->updateOrCreate(
            ['email' => 'owner@embergrill.example'],
            [
                'restaurant_id' => $restaurant->id,
                'name' => 'Ember Grill Owner',
                'password' => Hash::make($this->adminPassword()),
                'email_verified_at' => now(),
            ],
        );
    }

    /**
     * `ADMIN_PASSWORD`, or the published default where that is survivable.
     */
    private function adminPassword(): string
    {
        $configured = config('restaurantline.admin_password');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (app()->isProduction()) {
            throw new RuntimeException(
                'DemoRestaurantSeeder would create owner@embergrill.example with the password published '
                .'in this repository, and this is production. Set ADMIN_PASSWORD and run it again, or do '
                .'not seed demo data here at all.',
            );
        }

        /*
         * Laravel's docblock says `$command` is a Command. It is null whenever
         * a seeder is resolved from the container and run directly, which the
         * test suite does — hence the nullsafe call PHPStan is objecting to on
         * the strength of that docblock.
         */
        // @phpstan-ignore-next-line nullsafe.neverNull
        $this->command?->warn(
            'Dashboard login: owner@embergrill.example / '.self::DEFAULT_PASSWORD.' — published in the '
            .'README, so set ADMIN_PASSWORD before anyone else can reach /admin.',
        );

        return self::DEFAULT_PASSWORD;
    }
}
