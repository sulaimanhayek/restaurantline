<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Payment state for an order.
 *
 * Note what is absent: there is no state representing a card taken over the
 * phone, because restaurantline never accepts one. Payment is a link sent by
 * SMS after the call, or cash on delivery. See app/Services/Payments/README.md
 * for why.
 */
enum PaymentStatus: string implements HasColor, HasLabel
{
    case Unpaid = 'unpaid';

    /** A payment link has been generated and sent by SMS. */
    case LinkSent = 'link_sent';

    case Paid = 'paid';

    /** Customer pays the driver, or at the counter. */
    case CashOnCollection = 'cash_on_collection';

    case Refunded = 'refunded';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::LinkSent => 'Payment link sent',
            self::Paid => 'Paid',
            self::CashOnCollection => 'Cash on collection/delivery',
            self::Refunded => 'Refunded',
            self::Failed => 'Payment failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::LinkSent => 'info',
            self::Unpaid, self::CashOnCollection => 'warning',
            self::Refunded => 'gray',
            self::Failed => 'danger',
        };
    }

    /**
     * The short form used on a kitchen ticket, where a tag has room for a word.
     *
     * `label()` stays the long, unambiguous wording the dashboard needs;
     * "Cash on collection/delivery" wrapped onto three lines of a card is not
     * more informative than "Cash" to the person holding the bag.
     */
    public function ticketLabel(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::LinkSent => 'Link sent',
            self::Paid => 'Paid',
            self::CashOnCollection => 'Cash',
            self::Refunded => 'Refunded',
            self::Failed => 'Payment failed',
        };
    }

    /**
     * Is there money still to take when this order is handed over?
     *
     * Not the inverse of `isSettled()`, and deliberately so. A cash order is
     * settled in the sense that nothing has gone wrong with it, and still
     * needs somebody to take the money and count out change — which is the
     * only thing the person at the pass cares about.
     */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::Unpaid, self::LinkSent, self::CashOnCollection], true);
    }

    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::CashOnCollection], true);
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
