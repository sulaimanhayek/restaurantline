<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\OrderStatus;
use App\Events\OrderReceived;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * Stamps and broadcasts every status change, from wherever it came.
 *
 * An order's status moves in at least four places already — the confirm tool
 * endpoint, the order edit form, the table actions, the kitchen display — and
 * a fifth will be added by whoever forks this. Dispatching from each of those
 * would work until one of them forgot, and the symptom of forgetting is a
 * kitchen screen that is quietly out of date, which nobody reports as a bug
 * because it looks like a screen that simply has nothing new on it.
 *
 * Hanging it off the model instead means a status change cannot happen without
 * the display hearing about it — and, for the same reason, without the
 * matching `*_at` column being filled in.
 *
 * @see docs/DECISIONS.md #0034
 */
final class OrderObserver
{
    /**
     * Stamp the timestamp belonging to the status being moved into.
     *
     * Runs before the write rather than after it so the column lands in the
     * same UPDATE, which keeps a row from ever being readable in the state
     * where the status has moved and the timestamp has not.
     *
     * An explicitly supplied value wins. The agent's confirm endpoint sets
     * `confirmed_at` itself, and a fork backfilling historical orders will set
     * whatever it is backfilling; neither should be overwritten with "now".
     */
    public function updating(Order $order): void
    {
        if (! $order->isDirty('status')) {
            return;
        }

        $column = $order->status->timestampColumn();

        if ($column === null || $order->{$column} !== null) {
            return;
        }

        $order->{$column} = CarbonImmutable::now();
    }

    public function created(Order $order): void
    {
        if ($order->status->isLiveOnKitchenDisplay()) {
            OrderReceived::dispatch($order);
        }
    }

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        // `updated` fires after syncChanges() and before syncOriginal(), so the
        // original attributes are still the pre-save ones. Casts are applied,
        // so this is an OrderStatus and not a string.
        $previous = $order->getOriginal('status');

        if (! $previous instanceof OrderStatus) {
            return;
        }

        $wasLive = $previous->isLiveOnKitchenDisplay();
        $isLive = $order->status->isLiveOnKitchenDisplay();

        // An order arriving on the display, which is the one a screen chimes
        // for. Everything else is a card changing colour or disappearing.
        if ($isLive && ! $wasLive) {
            OrderReceived::dispatch($order);

            return;
        }

        // Nothing on the kitchen channel for a status moving between two
        // states the display never shows — draft to confirming happens in the
        // middle of a phone call and is none of the kitchen's business.
        if ($isLive || $wasLive) {
            OrderStatusChanged::dispatch($order, $previous);
        }
    }
}
