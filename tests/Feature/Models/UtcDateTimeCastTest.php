<?php

declare(strict_types=1);

use App\Models\OpeningHourOverride;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Every datetime column in this schema holds an instant, not a wall clock.
 *
 * The columns are `timestamp without time zone`, so the database stores bare
 * digits and the application supplies the convention that they mean UTC.
 * Laravel's own `datetime` cast does not enforce that convention on the way in
 * — it formats whatever Carbon it is handed and discards the offset — which is
 * harmless right up until a restaurant-local time is written.
 *
 * This application writes restaurant-local times constantly: opening hours,
 * prep estimates, and everything a caller is told are all local by nature. A
 * ready time of 16:00 in London went into the column as "16:00" and came back
 * as 16:00 UTC, an hour late. Nothing threw. The order simply said the wrong
 * time, and the kitchen display would have counted down to the wrong minute.
 *
 * @see docs/DECISIONS.md #0021
 */
beforeEach(function (): void {
    $this->restaurant = alwaysOpen(restaurant());
});

it('stores a restaurant-local time as the instant it actually is', function (): void {
    $order = Order::factory()->for($this->restaurant)->create([
        // 4pm in London during BST, which is 15:00 UTC. The bug wrote "16:00".
        'estimated_ready_at' => CarbonImmutable::parse('2026-09-07 16:00:00', 'Europe/London'),
    ]);

    expect(DB::table('orders')->where('id', $order->id)->value('estimated_ready_at'))
        ->toStartWith('2026-09-07 15:00:00');
});

it('reads the instant back unchanged', function (): void {
    $local = CarbonImmutable::parse('2026-09-07 16:00:00', 'Europe/London');

    $order = Order::factory()->for($this->restaurant)->create(['estimated_ready_at' => $local]);

    expect($order->fresh()?->estimated_ready_at?->equalTo($local))->toBeTrue();
});

/**
 * The round trip is what the application actually depends on: a time written in
 * one timezone and read back in another has to still be the same moment, and
 * has to render back to the same wall clock when converted home.
 */
it('survives a round trip through a column with no offset in it', function (): void {
    $local = CarbonImmutable::parse('2026-09-07 16:00:00', 'Europe/London');

    $order = Order::factory()->for($this->restaurant)->create(['estimated_ready_at' => $local]);

    expect($order->fresh()?->estimated_ready_at?->setTimezone('Europe/London')->format('g:ia'))
        ->toBe('4:00pm');
});

it('takes a UTC time at face value', function (): void {
    $order = Order::factory()->for($this->restaurant)->create([
        'estimated_ready_at' => CarbonImmutable::parse('2026-09-07 15:00:00', 'UTC'),
    ]);

    expect(DB::table('orders')->where('id', $order->id)->value('estimated_ready_at'))
        ->toStartWith('2026-09-07 15:00:00');
});

/**
 * A bare string has no offset, so there is nothing to convert and converting
 * anyway would move it. Seeders and fixtures write these.
 */
it('leaves a bare string alone', function (): void {
    $order = Order::factory()->for($this->restaurant)->create([
        'estimated_ready_at' => '2026-09-07 15:00:00',
    ]);

    expect(DB::table('orders')->where('id', $order->id)->value('estimated_ready_at'))
        ->toStartWith('2026-09-07 15:00:00');
});

it('keeps null null', function (): void {
    $order = Order::factory()->for($this->restaurant)->create(['estimated_ready_at' => null]);

    expect($order->fresh()?->estimated_ready_at)->toBeNull();
});

it('always hands back a UTC Carbon, whatever went in', function (): void {
    $order = Order::factory()->for($this->restaurant)->create([
        'estimated_ready_at' => CarbonImmutable::parse('2026-09-07 16:00:00', 'Europe/London'),
    ]);

    expect($order->fresh()?->estimated_ready_at?->timezone->getName())->toBe('UTC');
});

/**
 * The one place this cast must not be used, tested so nobody adds it there.
 *
 * A date column holds a day, not an instant. Converting a local midnight to UTC
 * lands it at 23:00 the day before, which would silently move a holiday closure
 * onto the wrong date — and a closure on the wrong day is a full day of orders
 * taken for a kitchen nobody is standing in.
 */
it('does not touch a date column', function (): void {
    $override = OpeningHourOverride::factory()->for($this->restaurant)->create([
        'date' => CarbonImmutable::parse('2026-12-25 00:00:00', 'Europe/London'),
    ]);

    expect(DB::table('opening_hour_overrides')->where('id', $override->id)->value('date'))
        ->toStartWith('2026-12-25');
});
