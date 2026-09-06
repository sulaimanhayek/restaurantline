<?php

declare(strict_types=1);

use App\Models\MenuCategory;
use App\Models\OpeningHour;
use Carbon\CarbonImmutable;

it('reads a normal shift back the way a person would say it', function (): void {
    $hour = OpeningHour::factory()->for(restaurant())->forDay(2)->dinner()->create();

    expect($hour->dayName())->toBe('Tuesday')
        // Not "17:00 to 22:30" — nobody answering a takeaway phone says that.
        ->and($hour->spoken())->toBe('5pm to 10:30pm');
});

it('says midnight and midday rather than reading the clock out', function (): void {
    $late = OpeningHour::factory()->for(restaurant())->forDay(5)->create([
        'opens_at' => '12:00:00',
        'closes_at' => '00:00:00',
        'closes_next_day' => true,
    ]);

    expect($late->spoken())->toBe('midday to midnight');
});

it('keeps a past-midnight close explicit rather than inferring it', function (): void {
    $hour = OpeningHour::factory()->for(restaurant())->forDay(5)->lateNight()->create();

    // Inferring this from closes_at < opens_at would break a genuine 00:00
    // close, which is why the column exists at all.
    expect($hour->closes_next_day)->toBeTrue();
});

it('serves a lunch category only inside its window', function (): void {
    $category = MenuCategory::factory()
        ->for(restaurant())
        ->availableBetween('11:30:00', '15:00:00')
        ->create();

    expect($category->isAvailableAt(CarbonImmutable::parse('2026-09-09 12:30')))->toBeTrue()
        ->and($category->isAvailableAt(CarbonImmutable::parse('2026-09-09 19:30')))->toBeFalse()
        ->and($category->isAvailableAt(CarbonImmutable::parse('2026-09-09 11:29')))->toBeFalse();
});

it('serves a weekday-only category only on those days', function (): void {
    $category = MenuCategory::factory()
        ->for(restaurant())
        ->onDays([1, 2, 3, 4, 5])
        ->create();

    // 2026-09-09 is a Wednesday, 2026-09-13 a Sunday.
    expect($category->isAvailableAt(CarbonImmutable::parse('2026-09-09 12:30')))->toBeTrue()
        ->and($category->isAvailableAt(CarbonImmutable::parse('2026-09-13 12:30')))->toBeFalse();
});

it('handles a category window that wraps past midnight', function (): void {
    $category = MenuCategory::factory()
        ->for(restaurant())
        ->availableBetween('22:00:00', '02:00:00')
        ->create();

    expect($category->isAvailableAt(CarbonImmutable::parse('2026-09-09 23:30')))->toBeTrue()
        ->and($category->isAvailableAt(CarbonImmutable::parse('2026-09-09 01:00')))->toBeTrue()
        ->and($category->isAvailableAt(CarbonImmutable::parse('2026-09-09 15:00')))->toBeFalse();
});

it('takes an inactive category off the menu whatever the clock says', function (): void {
    $category = MenuCategory::factory()->for(restaurant())->inactive()->create();

    expect($category->isAvailableAt(CarbonImmutable::parse('2026-09-09 12:30')))->toBeFalse();
});

it('has no availability window when none is set', function (): void {
    $category = MenuCategory::factory()->for(restaurant())->create();

    expect($category->hasAvailabilityWindow())->toBeFalse()
        ->and($category->isAvailableAt(CarbonImmutable::parse('2026-09-13 03:00')))->toBeTrue();
});
