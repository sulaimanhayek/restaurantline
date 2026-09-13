<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order the kitchen can see has moved, or has just left the display.
 *
 * Both directions are broadcast on the same channel. A screen that only
 * listened for arrivals would keep showing an order somebody completed on
 * another screen, which is exactly the failure a shared display exists to
 * prevent.
 *
 * `$previousStatus` is passed as a value rather than read back off the model,
 * because this event is queued: by the time the broadcast is built, the model
 * has been re-fetched from the database and its "original" attributes are the
 * ones it was loaded with, not the ones it had when this fired.
 *
 * @see docs/DECISIONS.md #0034, #0035
 */
final class OrderStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $previousStatus,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('restaurant.'.$this->order->restaurant_id.'.kitchen')];
    }

    public function broadcastAs(): string
    {
        return 'order.status-changed';
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
            'previous_status' => $this->previousStatus->value,
        ];
    }
}
