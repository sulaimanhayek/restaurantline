<?php

declare(strict_types=1);

use App\Filament\Pages\RestaurantSettings;
use App\Filament\Support\MoneyInput;
use App\Models\Restaurant;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| MoneyInput
|--------------------------------------------------------------------------
|
| Prices are stored as integer pence and typed as pounds. Every one of these
| tests exists because the first version of that conversion was wrong in a way
| nothing else would have caught: Filament hands the dehydrator a float, and a
| closure typed `int|string|null` silently truncated 2.99 to 2 and stored 200.
| Nothing threw. The form saved. The delivery fee was just quietly wrong.
|
*/

beforeEach(function (): void {
    $this->restaurant = restaurant([
        'currency' => 'GBP',
        'base_delivery_fee' => 299,
        'minimum_order_value' => 1500,
    ]);

    actingAs(User::factory()->create(['restaurant_id' => $this->restaurant->id]));
});

it('shows pence as pounds', function (): void {
    Livewire::test(RestaurantSettings::class)
        ->assertSet('data.base_delivery_fee', '2.99')
        ->assertSet('data.minimum_order_value', '15.00');
});

it('stores pounds as pence', function (string $typed, int $expected): void {
    Livewire::test(RestaurantSettings::class)
        ->set('data.base_delivery_fee', $typed)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->restaurant->fresh()->base_delivery_fee)->toBe($expected);
})->with([
    // The original bug: anything with a fractional part lost it entirely.
    'pounds and pence' => ['3.45', 345],
    'the reported case' => ['2.99', 299],
    // (int) (12.50 * 100) is 1249 on some platforms. round() is not optional.
    'a value floats represent badly' => ['12.50', 1250],
    'under a pound' => ['0.80', 80],
    'whole pounds' => ['4', 400],
    'free delivery' => ['0', 0],
]);

it('round-trips an untouched price unchanged', function (): void {
    Livewire::test(RestaurantSettings::class)
        ->call('save')
        ->assertHasNoErrors();

    $restaurant = Restaurant::current();

    expect($restaurant->base_delivery_fee)->toBe(299)
        ->and($restaurant->minimum_order_value)->toBe(1500);
});

it('takes the currency symbol from the restaurant', function (string $currency, string $symbol): void {
    expect(MoneyInput::symbol($currency))->toBe($symbol);
})->with([
    ['GBP', '£'],
    ['EUR', '€'],
    // Locale-aware, and rightly so: under the app's en_GB formatting a dollar
    // price reads "US$" and a yen price "JP¥", which is the disambiguation an
    // operator wants rather than a bare glyph shared by four currencies.
    ['USD', 'US$'],
    ['JPY', 'JP¥'],
]);
