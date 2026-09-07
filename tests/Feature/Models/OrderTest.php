<?php

declare(strict_types=1);

use App\Enums\ConversationOutcome;
use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;

it('shows only live orders on the kitchen display', function (): void {
    $restaurant = restaurant();

    Order::factory()->for($restaurant)->confirming()->create();
    Order::factory()->for($restaurant)->confirmed()->create();
    Order::factory()->for($restaurant)->withStatus(OrderStatus::Preparing)->create();
    Order::factory()->for($restaurant)->withStatus(OrderStatus::Completed)->create();
    Order::factory()->for($restaurant)->cancelled()->create();

    expect(Order::live()->count())->toBe(2);
});

it('spaces the order number so a voice reads it digit by digit', function (): void {
    $order = Order::factory()->for(restaurant())->create(['order_number' => '147']);

    expect($order->spokenOrderNumber())->toBe('1 4 7');
});

it('times an order from confirmation, not from when the row appeared', function (): void {
    $order = Order::factory()->for(restaurant())->create([
        'created_at' => now()->subMinutes(40),
        'confirmed_at' => now()->subMinutes(12),
        'status' => OrderStatus::Preparing,
    ]);

    expect($order->minutesSinceConfirmed())->toBe(12);
});

it('falls back to created_at for an order that was never confirmed', function (): void {
    $order = Order::factory()->for(restaurant())->confirming()->create([
        'created_at' => now()->subMinutes(6),
        'confirmed_at' => null,
    ]);

    expect($order->minutesSinceConfirmed())->toBe(6);
});

it('builds a delivery order against a verified address', function (): void {
    $restaurant = restaurant();
    $order = Order::factory()->for($restaurant)->delivery()->create();

    expect($order->isDelivery())->toBeTrue()
        ->and($order->address)->not->toBeNull()
        // Phase 3 enforces this at the endpoint; the factory must not model a
        // state the endpoint would reject.
        ->and($order->address->isVerified())->toBeTrue()
        ->and($order->total)->toBe($order->subtotal + $order->delivery_fee);
});

it('reads an address back without the county and country', function (): void {
    $address = Address::factory()->for(restaurant())->create([
        'line_1' => '42 Cheshire Street',
        'line_2' => 'Flat B',
        'city' => 'London',
        'postcode' => 'E2 6EH',
        'country' => 'GB',
    ]);

    $spoken = $address->spoken();

    expect($spoken)->toContain('42 Cheshire Street', 'Flat B', 'E2 6EH');
    // Reading a country back to someone who just told you where they live is
    // how you lose them.
    expect($spoken)->not->toContain('GB');
});

it('keeps what the caller actually said, however mangled', function (): void {
    $address = Address::factory()->for(restaurant())->create([
        'raw_spoken_text' => 'forty two cheshire street, flat b, e2 something',
    ]);

    // The debugging goldmine: when the geocoder gets it wrong, this is the
    // only record of what it was given.
    expect($address->fresh()->raw_spoken_text)->toBe('forty two cheshire street, flat b, e2 something');
});

it('links a conversation to the order it produced', function (): void {
    $restaurant = restaurant();
    $conversation = Conversation::factory()->for($restaurant)->create();

    $order = Order::factory()->for($restaurant)->confirmed()->create([
        'conversation_id' => $conversation->id,
        'elevenlabs_conversation_id' => $conversation->elevenlabs_conversation_id,
    ]);

    expect($conversation->fresh()->producedAnOrder())->toBeTrue()
        ->and($conversation->fresh()->order->is($order))->toBeTrue();
});

it('keeps a row for a call that produced nothing', function (): void {
    $restaurant = restaurant();
    Conversation::factory()->for($restaurant)->abandoned()->create();
    Conversation::factory()->for($restaurant)->create();

    expect(Conversation::withoutOrder()->count())->toBe(2)
        ->and(Conversation::flagged()->count())->toBe(1);
});

it('flags the outcomes a human should actually look at', function (): void {
    expect(ConversationOutcome::OrderAbandoned->warrantsReview())->toBeTrue()
        ->and(ConversationOutcome::CallFailed->warrantsReview())->toBeTrue()
        ->and(ConversationOutcome::OrderPlaced->warrantsReview())->toBeFalse()
        // Someone ringing from outside the delivery area is a normal call,
        // not a failure — flagging it would bury the real problems.
        ->and(ConversationOutcome::OutsideDeliveryArea->warrantsReview())->toBeFalse();
});

it('scopes customer phone numbers per restaurant, not globally', function (): void {
    $first = restaurant();
    $second = restaurant(['slug' => 'second-shop']);

    Customer::factory()->for($first)->create(['phone_number' => '+447700900123']);
    $duplicate = Customer::factory()->for($second)->create(['phone_number' => '+447700900123']);

    // Multi-tenant-shaped from day one: the same caller can exist under two
    // restaurants without the unique index rejecting the second.
    expect($duplicate->exists)->toBeTrue()
        ->and(Customer::where('phone_number', '+447700900123')->count())->toBe(2);
});
