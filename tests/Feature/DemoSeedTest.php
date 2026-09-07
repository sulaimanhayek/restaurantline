<?php

declare(strict_types=1);

use App\Enums\ModifierKind;
use App\Enums\PaymentStatus;
use App\Models\Conversation;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\Restaurant;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoRestaurantSeeder;
use Database\Seeders\SampleMenuSeeder;

/**
 * `docker compose up` has to produce a working demo. That promise is only
 * worth making if something checks it, so this exercises the seeders the way
 * a first boot does.
 */
beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->restaurant = Restaurant::current();
});

it('comes up with one restaurant that has hours, delivery bands and a menu', function (): void {
    expect(Restaurant::count())->toBe(1)
        ->and($this->restaurant->slug)->toBe('ember-grill')
        ->and($this->restaurant->openingHours()->count())->toBeGreaterThan(0)
        ->and($this->restaurant->deliveryFeeRules()->count())->toBeGreaterThan(0)
        ->and($this->restaurant->menuItems()->count())->toBeGreaterThan(15);
});

it('runs twice without duplicating the restaurant or the menu', function (): void {
    $items = MenuItem::count();

    $this->seed(DemoRestaurantSeeder::class);
    $this->seed(SampleMenuSeeder::class);

    expect(Restaurant::count())->toBe(1)
        ->and(MenuItem::count())->toBe($items);
});

it('has a catch-all delivery band so no address inside the radius is unpriced', function (): void {
    expect($this->restaurant->deliveryFeeRules()->whereNull('up_to_metres')->exists())->toBeTrue();
});

it('closes on Mondays and runs past midnight on Fridays', function (): void {
    expect($this->restaurant->openingHours()->where('day_of_week', 1)->exists())->toBeFalse()
        ->and($this->restaurant->openingHours()->where('day_of_week', 5)->value('closes_next_day'))->toBeTrue();
});

it('exercises every modifier kind, because a demo menu that only has options teaches nothing', function (): void {
    $kinds = Modifier::query()
        ->where('restaurant_id', $this->restaurant->id)
        ->distinct()
        ->pluck('kind')
        ->map(fn (ModifierKind $kind): string => $kind->value)
        ->all();

    expect($kinds)->toContain('option', 'addon', 'removal', 'swap');
});

it('gives every menu item at least one thing a caller might actually say', function (): void {
    $withoutAliases = MenuItem::query()
        ->where('restaurant_id', $this->restaurant->id)
        ->get()
        ->filter(fn (MenuItem $item): bool => $item->spoken_aliases === null || $item->spoken_aliases === [])
        ->pluck('name')
        ->all();

    expect($withoutAliases)->toBe([]);
});

it('sets up the per-item overrides the schema exists for', function (): void {
    $double = MenuItem::where('slug', 'double-beef-burger')->firstOrFail();
    $veggie = MenuItem::where('slug', 'halloumi-avocado-burger')->firstOrFail();
    $ember = MenuItem::where('slug', 'ember-chicken-burger')->firstOrFail();

    $patty = Modifier::where('slug', 'extra-patty')->firstOrFail();
    $extras = $ember->modifierGroups()->where('slug', 'burger-extras')->firstOrFail();

    $bacon = $veggie->modifierOverrides()->where('modifiers.slug', 'bacon')->firstOrFail();

    expect($patty->price_delta)->toBe(300)
        // Repriced on this one item, untouched everywhere else.
        ->and($double->resolvedPriceDelta($patty))->toBe(250)
        ->and($ember->resolvedSelectionRules($extras)['max'])->toBe(3)
        // Hidden on the vegetarian burger without deleting the shared modifier.
        ->and((bool) $bacon->getRelationValue('pivot')->getAttribute('is_available_override'))->toBeFalse();
});

it('prices every seeded order as the sum of its lines plus delivery', function (): void {
    $orders = Order::with('items')->get();

    expect($orders)->not->toBeEmpty();

    foreach ($orders as $order) {
        expect($order->subtotal)->toBe((int) $order->items->sum('line_total'))
            ->and($order->total)->toBe($order->subtotal + $order->delivery_fee);
    }
});

it('leaves some calls without an order, because those are the ones worth reading', function (): void {
    expect(Conversation::withoutOrder()->count())->toBeGreaterThan(0)
        ->and(Conversation::flagged()->count())->toBeGreaterThan(0)
        ->and(Order::live()->count())->toBeGreaterThan(0);
});

it('never records a payment taken over the phone', function (): void {
    $statuses = Order::query()->distinct()->pluck('payment_status')->map(
        fn (PaymentStatus $status): string => $status->value,
    )->all();

    expect($statuses)->each->toBeIn(['unpaid', 'link_sent', 'paid', 'cash_on_collection', 'refunded', 'failed']);
});
