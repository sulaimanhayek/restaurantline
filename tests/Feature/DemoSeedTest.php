<?php

declare(strict_types=1);

use App\Enums\ModifierKind;
use App\Enums\PaymentStatus;
use App\Models\Conversation;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\SmsMessage;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoOrdersSeeder;
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

it('runs the day of traffic twice without duplicating any of it', function (): void {
    $before = [
        'conversations' => Conversation::count(),
        'orders' => Order::count(),
        'items' => OrderItem::count(),
        'texts' => SmsMessage::count(),
    ];

    $this->seed(DemoOrdersSeeder::class);

    // The reason this is worth a test: the conversation ids used to be random,
    // which turned every `updateOrCreate` in the seeder into a `create` and
    // filled the dashboard with copies of the same evening. Naming them made
    // reseeding collide instead, which is at least loud.
    expect(Conversation::count())->toBe($before['conversations'])
        ->and(Order::count())->toBe($before['orders'])
        ->and(OrderItem::count())->toBe($before['items'])
        ->and(SmsMessage::count())->toBe($before['texts'])
        // An order is replaced rather than merged, and the texts' foreign key
        // is `set null`, so a careless delete leaves them on the SMS screen
        // attached to nothing.
        ->and(SmsMessage::whereNull('order_id')->count())->toBe(0);
});

it('seeds one call long enough to be worth playing to somebody', function (): void {
    $call = Conversation::where('elevenlabs_conversation_id', 'conv_demo_delivery')->firstOrFail();

    $turns = $call->transcriptTurns();
    $said = implode(' ', array_column($turns, 'message'));

    // `kitchenline:demo:render conv_demo_delivery` is documented in the README
    // by that name, and it is the demo a forker shows a restaurant owner. Four
    // lines of shorthand would render to twenty seconds of nothing much.
    expect($turns)->toHaveCount(20)
        ->and($call->order)->not->toBeNull()
        // All four rules, in the order a real call meets them.
        ->and($said)->toContain('read that back')
        ->and($said)->toContain('read the whole thing back')
        ->and($said)->toContain('not able to take card details over the phone')
        ->and($said)->toContain('get you a person');
});

it('alternates speakers often enough for two voices to be worth it', function (): void {
    $roles = array_column(
        Conversation::where('elevenlabs_conversation_id', 'conv_demo_delivery')->firstOrFail()->transcriptTurns(),
        'role',
    );

    // A transcript where one side says six things in a row renders as a
    // monologue, whichever voices you give it.
    expect($roles)->toBe(array_map(
        fn (int $index): string => $index % 2 === 0 ? 'agent' : 'user',
        range(0, count($roles) - 1),
    ));
});
