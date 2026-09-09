<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The lifecycle of an order.
 *
 * The two states that matter most here are `confirming` and `confirmed`.
 * An order created by the voice agent always lands in `confirming` — it is not
 * a real order yet, it is a basket the agent has just read back to the caller.
 * Only once the caller says yes does `POST /orders/{order}/confirm` move it to
 * `confirmed`, which is the point at which the kitchen sees it and the
 * confirmation SMS goes out.
 *
 * That two-step exists so a caller who hangs up mid-sentence leaves behind an
 * abandoned `confirming` row you can inspect, rather than a phantom order the
 * kitchen starts cooking.
 */
enum OrderStatus: string implements HasColor, HasLabel
{
    /** Being assembled. Nothing has been read back to the caller yet. */
    case Draft = 'draft';

    /** Read back to the caller; awaiting their yes. Not visible to the kitchen. */
    case Confirming = 'confirming';

    /** The caller agreed. This is a real order. */
    case Confirmed = 'confirmed';

    /** The kitchen has acknowledged it. */
    case Accepted = 'accepted';

    case Preparing = 'preparing';

    /** Ready for collection, or ready for the driver. */
    case Ready = 'ready';

    case Completed = 'completed';

    case Cancelled = 'cancelled';

    /** Something went wrong that was not the caller's choice. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirming => 'Awaiting confirmation',
            self::Confirmed => 'Confirmed',
            self::Accepted => 'Accepted',
            self::Preparing => 'Preparing',
            self::Ready => 'Ready',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Failed',
        };
    }

    /**
     * Colour used by the Filament resources and the kitchen display.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft, self::Confirming => 'gray',
            self::Confirmed => 'warning',
            self::Accepted, self::Preparing => 'info',
            self::Ready => 'success',
            self::Completed => 'success',
            self::Cancelled => 'danger',
            self::Failed => 'danger',
        };
    }

    /**
     * Does this order appear on the kitchen display?
     *
     * `confirming` deliberately does not: the caller has not agreed to it yet.
     *
     * @return list<self>
     */
    public static function liveOnKitchenDisplay(): array
    {
        return [self::Confirmed, self::Accepted, self::Preparing, self::Ready];
    }

    /**
     * States from which no further transition is possible.
     *
     * @return list<self>
     */
    public static function terminal(): array
    {
        return [self::Completed, self::Cancelled, self::Failed];
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }

    public function isLiveOnKitchenDisplay(): bool
    {
        return in_array($this, self::liveOnKitchenDisplay(), true);
    }

    /**
     * Whether this order counts as a real, customer-agreed order.
     */
    public function isCommitted(): bool
    {
        return ! in_array($this, [self::Draft, self::Confirming], true);
    }

    /*
     * Filament reads these three contracts directly, so a status rendered as a
     * badge picks up its own wording and colour with no mapping at the call
     * site. The alternative — a formatStateUsing closure in every resource that
     * shows a status — is the same match statement copied six times, and the
     * copies drift.
     *
     * The domain methods above stay the canonical ones; these only adapt them.
     *
     * @see docs/DECISIONS.md #0026
     */

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return $this->color();
    }
}
