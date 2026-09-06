<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What actually happened on a call.
 *
 * Every call gets a Conversation row whether or not it produced an order, and
 * this enum is the reason why. The calls that did *not* end in an order are the
 * most valuable data in the system: they are where you find out the agent keeps
 * mishearing one menu item, or that half your callers live outside the radius
 * you configured.
 */
enum ConversationOutcome: string
{
    /** Still in progress, or the post-call webhook has not arrived yet. */
    case Pending = 'pending';

    case OrderPlaced = 'order_placed';

    /** The caller was building an order and hung up before confirming. */
    case OrderAbandoned = 'order_abandoned';

    /** Caller only wanted information — opening hours, whether we deliver to them. */
    case EnquiryOnly = 'enquiry_only';

    /** Caller asked for a human, or the agent decided to hand over. */
    case Escalated = 'escalated';

    /** Caller's address was outside the delivery radius. */
    case OutsideDeliveryArea = 'outside_delivery_area';

    /** Restaurant was closed at the time of the call. */
    case OutOfHours = 'out_of_hours';

    /** The call never really started — see `call_initiation_failure` webhooks. */
    case CallFailed = 'call_failed';

    /** Anything the analysis could not classify. Worth reviewing by hand. */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::OrderPlaced => 'Order placed',
            self::OrderAbandoned => 'Abandoned mid-order',
            self::EnquiryOnly => 'Enquiry only',
            self::Escalated => 'Escalated to human',
            self::OutsideDeliveryArea => 'Outside delivery area',
            self::OutOfHours => 'Called out of hours',
            self::CallFailed => 'Call failed',
            self::Unknown => 'Unclassified',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::OrderPlaced => 'success',
            self::EnquiryOnly, self::Pending => 'gray',
            self::OrderAbandoned, self::Unknown => 'warning',
            self::Escalated, self::OutsideDeliveryArea, self::OutOfHours => 'info',
            self::CallFailed => 'danger',
        };
    }

    /**
     * Outcomes that should automatically be flagged for a human to review.
     *
     * Deliberately excludes the benign ones — an enquiry-only call or a
     * successful order is not a defect, and flagging them would bury the
     * signal.
     *
     * @return list<self>
     */
    public static function warrantingReview(): array
    {
        return [
            self::OrderAbandoned,
            self::CallFailed,
            self::Unknown,
        ];
    }

    public function warrantsReview(): bool
    {
        return in_array($this, self::warrantingReview(), true);
    }
}
