<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order has become the kitchen's problem.
 *
 * Fired when an order enters the set of statuses the display shows — in
 * practice, when the caller says yes and `POST /orders/{order}/confirm` moves
 * it out of `confirming`. This is the event a screen chimes for, and the one
 * to hang a ticket printer or a pager off.
 *
 * It carries almost nothing on purpose. See docs/DECISIONS.md #0034: the
 * display re-queries when it hears this, so the payload never has to be kept
 * in step with the order it describes.
 *
 * @see docs/DECISIONS.md #0034, #0035
 */
final class OrderReceived implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Order $order) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('restaurant.'.$this->order->restaurant_id.'.kitchen')];
    }

    /**
     * The name this goes out on the wire as.
     *
     * Without this it would be the fully qualified class name, which puts a
     * PHP namespace into every JavaScript listener and into whatever else ends
     * up subscribing. Renaming a class should not be a breaking change for
     * something outside this repository.
     */
    public function broadcastAs(): string
    {
        return 'order.received';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'status' => $this->order->status->value,
        ];
    }
}
