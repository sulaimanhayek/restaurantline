<?php

declare(strict_types=1);

use App\Enums\GeocodeProvider;
use App\Services\Geocoding\AddressValidator;
use App\Services\Geocoding\FakeGeocoder;
use App\Services\Geocoding\Geocoder;

/**
 * Address handling is the highest-risk step in a voice order: a wrong street is
 * the one mistake the caller cannot see coming and the restaurant pays for
 * twice. These tests pin the three rules AddressValidator exists to enforce —
 * weak candidates are never offered, lookalikes are reported as ambiguous, and
 * nothing is ever marked verified here.
 */
beforeEach(function (): void {
    // The seeded demo restaurant's own corner of east London, which is where
    // the fake geocoder's fixtures live.
    $this->restaurant = restaurant([
        'latitude' => 51.5155,
        'longitude' => -0.0722,
        'delivery_radius_metres' => 5000,
    ]);

    $this->validator = app(AddressValidator::class);
});

it('resolves a full address to one confident candidate', function (): void {
    $result = $this->validator->validate($this->restaurant, '15 Fournier Street, London E1 6QE');

    expect($result->isEmpty())->toBeFalse()
        ->and($result->isConfident())->toBeTrue()
        ->and($result->isAmbiguous())->toBeFalse()
        ->and($result->best()?->spoken())->toBe('15 Fournier Street, London, E1 6QE')
        ->and($result->best()?->withinDeliveryRadius)->toBeTrue();
});

it('lets a postcode outrank a street name the caller got wrong', function (): void {
    // "Fornier" is how callers say Fournier. The postcode carries the address.
    $result = $this->validator->validate($this->restaurant, 'E1 6QE');

    expect($result->best()?->geocode->line1)->toBe('15 Fournier Street')
        ->and($result->best()?->confidence())->toBe(0.95)
        ->and($result->isConfident())->toBeTrue();
});

it('does not pretend to be sure about a mispronounced street', function (): void {
    $result = $this->validator->validate($this->restaurant, '15 Fornier Street');

    expect($result->best()?->geocode->line1)->toBe('15 Fournier Street')
        // Found, but not confidently enough to skip reading it back.
        ->and($result->isConfident())->toBeFalse();
});

it('calls a street name with no house number ambiguous', function (): void {
    $result = $this->validator->validate($this->restaurant, 'Brick Lane');

    expect($result->isAmbiguous())->toBeTrue()
        ->and($result->isConfident())->toBeFalse()
        ->and($result->candidates)->toHaveCount(3)
        // Nearest first, so the agent offers the likeliest one aloud first.
        ->and($result->best()?->geocode->line1)->toBe('7 Brick Lane');
});

it('treats a flat sharing a street address as ambiguous rather than guessing', function (): void {
    $result = $this->validator->validate($this->restaurant, '42 Cheshire Street, London E2 6EH');

    expect($result->isAmbiguous())->toBeTrue()
        ->and($result->candidates)->toHaveCount(2)
        ->and($result->best()?->geocode->line2)->toBeNull()
        ->and($result->candidates[1]->geocode->line2)->toBe('Flat B');
});

it('reports an address it found but cannot deliver to', function (): void {
    $result = $this->validator->validate($this->restaurant, '14 High Street, Croydon CR0 1QG');

    expect($result->isEmpty())->toBeFalse()
        ->and($result->isOutsideDeliveryArea())->toBeTrue()
        ->and($result->deliverable())->toBeEmpty()
        ->and($result->best()?->distanceMetres)->toBeGreaterThan(15_000)
        ->and($result->best()?->toAgentArray()['distance_spoken'])->toStartWith('about 1');
});

it('separates "not found" from "too far"', function (): void {
    $result = $this->validator->validate($this->restaurant, '1 Buckingham Palace Road');

    expect($result->isEmpty())->toBeTrue()
        ->and($result->isOutsideDeliveryArea())->toBeFalse()
        ->and($result->best())->toBeNull();
});

it('finds nothing in silence', function (): void {
    expect($this->validator->validate($this->restaurant, '   ')->isEmpty())->toBeTrue();
});

it('never marks an address verified', function (): void {
    // Verification means a human heard it read back and agreed. That happens at
    // order creation, and a delivery order with an unverified address is
    // refused — so this must not arrive pre-verified.
    $attributes = $this->validator
        ->validate($this->restaurant, '15 Fournier Street, London E1 6QE')
        ->best()
        ?->toAddressAttributes();

    expect($attributes)->not->toBeNull()
        ->and($attributes)->not->toHaveKey('verified_at')
        ->and($attributes['geocode_provider'])->toBe(GeocodeProvider::Fake)
        ->and($attributes['distance_metres'])->toBeGreaterThan(0);
});

it('keeps delivering when the operator has not set the restaurant\'s coordinates', function (): void {
    // Refusing every order until someone fills in a latitude would be a worse
    // failure than accepting one we should have questioned.
    $unplaced = restaurant(['name' => 'No Pin Yet', 'latitude' => null, 'longitude' => null]);

    $result = $this->validator->validate($unplaced, '14 High Street, Croydon CR0 1QG');

    expect($result->best()?->distanceMetres)->toBe(0)
        ->and($result->best()?->withinDeliveryRadius)->toBeTrue()
        ->and($result->isOutsideDeliveryArea())->toBeFalse();
});

it('caps the candidates it offers a caller', function (): void {
    config()->set('restaurantline.geocoder.max_candidates', 2);

    expect(app(AddressValidator::class)->validate($this->restaurant, 'Brick Lane')->candidates)
        ->toHaveCount(2);
});

describe('the fake driver', function (): void {
    it('returns the same answer every time it is asked', function (): void {
        $geocoder = new FakeGeocoder;

        $first = $geocoder->geocode('Brick Lane', ['lat' => 51.5155, 'lon' => -0.0722]);
        $second = $geocoder->geocode('Brick Lane', ['lat' => 51.5155, 'lon' => -0.0722]);

        expect(array_map(static fn ($c): string => (string) $c->placeId, $first))
            ->toBe(array_map(static fn ($c): string => (string) $c->placeId, $second));
    });

    it('stamps its results so a fake can never be mistaken for a real one', function (): void {
        $candidate = (new FakeGeocoder)->geocode('15 Fournier Street')[0];

        expect($candidate->provider)->toBe(GeocodeProvider::Fake)
            ->and($candidate->placeId)->toStartWith('fake-');
    });

    it('lets a test supply its own streets', function (): void {
        $this->app->instance(Geocoder::class, new FakeGeocoder([
            ['line_1' => '1 Test Street', 'postcode' => 'E1 1AA', 'lat' => 51.5160, 'lon' => -0.0730],
        ]));

        $result = app(AddressValidator::class)->validate($this->restaurant, '1 Test Street, E1 1AA');

        expect($result->candidates)->toHaveCount(1)
            ->and($result->best()?->geocode->line1)->toBe('1 Test Street')
            ->and($result->isConfident())->toBeTrue();
    });

    it('is the driver a fresh clone comes up with', function (): void {
        expect(config('restaurantline.geocoder.driver'))->toBe('fake')
            ->and(app(Geocoder::class))->toBeInstanceOf(FakeGeocoder::class);
    });
});

it('refuses to boot with a geocoder driver nobody implemented', function (): void {
    config()->set('restaurantline.geocoder.driver', 'openstreetmap');
    $this->app->forgetInstance(Geocoder::class);

    expect(fn (): Geocoder => app(Geocoder::class))
        ->toThrow(InvalidArgumentException::class, 'openstreetmap');
});
