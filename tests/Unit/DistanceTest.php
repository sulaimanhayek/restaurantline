<?php

declare(strict_types=1);

use App\Support\Distance;

/**
 * The delivery radius, every fee band and the "sorry, you're out of our area"
 * sentence all hang off this one function, so it is checked against known
 * distances rather than against itself.
 */
it('measures a degree of latitude to within a few metres of the textbook figure', function (): void {
    // One degree of latitude is ~111.19 km everywhere on the globe.
    expect(Distance::haversineMetres(51.0, -0.1, 52.0, -0.1))
        ->toBeGreaterThan(111_100)
        ->toBeLessThan(111_300);
});

it('returns zero for a point measured against itself', function (): void {
    expect(Distance::haversineMetres(51.5218, -0.0715, 51.5218, -0.0715))->toBe(0);
});

it('is symmetric', function (): void {
    $there = Distance::haversineMetres(51.5218, -0.0715, 51.3762, -0.0982);
    $back = Distance::haversineMetres(51.3762, -0.0982, 51.5218, -0.0715);

    expect($there)->toBe($back);
});

it('puts Croydon well outside a five kilometre delivery radius', function (): void {
    // The restaurant on Brick Lane, and the fake geocoder's out-of-range row.
    expect(Distance::between(['lat' => 51.5218, 'lon' => -0.0715], ['lat' => 51.3762, 'lon' => -0.0982]))
        ->toBeGreaterThan(15_000);
});

it('keeps neighbouring east London streets inside it', function (): void {
    // 118 Brick Lane to 42 Cheshire Street: a few minutes' walk.
    expect(Distance::between(['lat' => 51.5218, 'lon' => -0.0715], ['lat' => 51.5240, 'lon' => -0.0705]))
        ->toBeLessThan(500);
});

it('reads distances the way a person says them', function (int $metres, string $expected): void {
    expect(Distance::spoken($metres))->toBe($expected);
})->with([
    'rounded to the nearest fifty' => [349, 'about 350 metres'],
    'never a false precision' => [212, 'about 200 metres'],
    'just under a kilometre reads as one, not as 1000 metres' => [999, 'about 1 kilometre'],
    'and stays singular' => [1000, 'about 1 kilometre'],
    'one decimal place' => [1240, 'about 1.2 kilometres'],
    'a trailing zero is dropped' => [2000, 'about 2 kilometres'],
    'out of area' => [16_800, 'about 16.8 kilometres'],
]);
