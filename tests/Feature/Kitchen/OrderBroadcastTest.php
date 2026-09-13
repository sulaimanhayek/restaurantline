<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Events\OrderReceived;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| What the kitchen hears
|--------------------------------------------------------------------------
|
| The observer is the only thing that dispatches these, so these tests are the
| only thing standing between a fork that adds a fifth place a status is set
| and a display that quietly stops updating.
|
*/

beforeEach(function (): void {
    $this->restaurant = restaurant();

    // These two events and nothing else. A bare Event::fake() would also fake
    // Eloquent's own model events, and the observer under test would never run.
    Event::fake([OrderReceived::class, OrderStatusChanged::class]);
});

/**
 * The channel an event says it belongs on, as the wire spells it.
 */
function broadcastChannel(OrderReceived|OrderStatusChanged $event): string
{
    return $event->broadcastOn()[0]->name;
}

describe('an order arriving', function (): void {
    it('announces one the moment the caller says yes', function (): void {
        $order = Order::factory()->for($this->restaurant)->confirming()->create();

        $order->update(['status' => OrderStatus::Confirmed]);

        Event::assertDispatched(
            OrderReceived::class,
            fn (OrderReceived $event): bool => $event->order->is($order),
        );
        Event::assertNotDispatched(OrderStatusChanged::class);
    });

    it('announces one created straight into a live status', function (): void {
        $order = Order::factory()->for($this->restaurant)->confirmed()->create();

        Event::assertDispatched(
            OrderReceived::class,
            fn (OrderReceived $event): bool => $event->order->is($order),
        );
    });

    it('says nothing while the caller is still on the phone', function (): void {
        $order = Order::factory()->for($this->restaurant)->create();

        $order->update(['status' => OrderStatus::Confirming]);

        Event::assertNothingDispatched();
    });

    it('says nothing for a change that is not a status change', function (): void {
        $order = Order::factory()->for($this->restaurant)->confirmed()->create();
        Event::fake([OrderReceived::class, OrderStatusChanged::class]);

        $order->update(['notes' => 'Ring the side door']);

        Event::assertNothingDispatched();
    });

    it('broadcasts on the restaurant it belongs to and no other', function (): void {
        $other = restaurant(['slug' => 'other-place']);
        Order::factory()->for($other)->confirmed()->create();

        Event::assertDispatched(
            OrderReceived::class,
            fn (OrderReceived $event): bool => broadcastChannel($event)
                === 'private-restaurant.'.$other->id.'.kitchen',
        );
    });

    it('carries an identifier rather than a copy of the order', function (): void {
        $order = Order::factory()->for($this->restaurant)->confirmed()->create();

        Event::assertDispatched(OrderReceived::class, function (OrderReceived $event) use ($order): bool {
            expect($event->broadcastAs())->toBe('order.received')
                ->and($event->broadcastWith())->toBe([
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => 'confirmed',
                ]);

            return true;
        });
    });
});

describe('an order moving', function (): void {
    it('announces a move between two statuses the display shows', function (): void {
        $order = Order::factory()->for($this->restaurant)->confirmed()->create();

        $order->update(['status' => OrderStatus::Preparing]);

        Event::assertDispatched(OrderStatusChanged::class, function (OrderStatusChanged $event): bool {
            expect($event->previousStatus)->toBe(OrderStatus::Confirmed)
                ->and($event->order->status)->toBe(OrderStatus::Preparing)
                ->and($event->broadcastAs())->toBe('order.status-changed');

            return true;
        });
    });

    it('announces one leaving the display, so other screens drop it', function (): void {
        $order = Order::factory()->for($this->restaurant)->withStatus(OrderStatus::Ready)->create();

        $order->update(['status' => OrderStatus::Completed]);

        Event::assertDispatched(
            OrderStatusChanged::class,
            fn (OrderStatusChanged $event): bool => $event->previousStatus === OrderStatus::Ready,
        );
    });

    it('announces a cancellation', function (): void {
        $order = Order::factory()->for($this->restaurant)->confirmed()->create();

        $order->update(['status' => OrderStatus::Cancelled]);

        Event::assertDispatched(OrderStatusChanged::class);
    });

    it('says nothing for a move between two statuses the display never shows', function (): void {
        $order = Order::factory()->for($this->restaurant)->confirming()->create();

        $order->update(['status' => OrderStatus::Failed]);

        Event::assertNothingDispatched();
    });
});

describe('status timestamps', function (): void {
    it('stamps the column belonging to the status being moved into', function (): void {
        $order = Order::factory()->for($this->restaurant)->confirmed()->create();

        $order->update(['status' => OrderStatus::Preparing]);
        $order->update(['status' => OrderStatus::Ready]);

        expect($order->ready_at)->not->toBeNull()
            ->and($order->completed_at)->toBeNull();

        $order->update(['status' => OrderStatus::Completed]);

        expect($order->completed_at)->not->toBeNull();
    });

    it('leaves a timestamp that was supplied alone', function (): void {
        $stamped = now()->subHours(3)->startOfSecond();

        $order = Order::factory()->for($this->restaurant)->confirming()->create();
        $order->update(['status' => OrderStatus::Confirmed, 'confirmed_at' => $stamped]);

        expect($order->confirmed_at?->equalTo($stamped))->toBeTrue();
    });

    it('has no column of its own for preparing', function (): void {
        expect(OrderStatus::Preparing->timestampColumn())->toBeNull()
            ->and(OrderStatus::Ready->timestampColumn())->toBe('ready_at');
    });
});
