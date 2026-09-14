<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How a confirmed order will be paid for.
 *
 * Two cases, and the absence of a third is the point: there is no way to record
 * a card taken over the phone, because no code path in this application accepts
 * one. See app/Services/Payments/README.md.
 *
 * Which of these an order gets is settled at confirmation. The restaurant
 * declares what it offers (`accepts_card_link`, `accepts_cash`); if it offers
 * one, the application picks it and the agent never raises the subject; if it
 * offers both, the agent asks and sends the answer to the confirm endpoint.
 *
 * @see docs/DECISIONS.md #0037
 */
enum PaymentMethod: string implements HasColor, HasLabel
{
    /** A Stripe Checkout link, texted to the caller once the call ends. */
    case CardLink = 'card_link';

    /** Cash to the driver, or at the counter on collection. */
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::CardLink => 'Card link by text',
            self::Cash => 'Cash',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CardLink => 'info',
            self::Cash => 'warning',
        };
    }

    /**
     * What the agent says when it has to offer the choice aloud.
     *
     * Phrased as the caller would hear it rather than as the enum is spelled.
     * "Card link" is a developer's word for it; "a payment link by text" is
     * what a person understands.
     *
     * The fulfilment type is a parameter because "cash on delivery" said to
     * somebody collecting their own dinner is a small, avoidable way of sounding
     * like a machine reading a field name.
     */
    public function spoken(bool $isDelivery = true): string
    {
        return match ($this) {
            self::CardLink => 'a payment link by text',
            self::Cash => $isDelivery ? 'cash on delivery' : 'cash when you collect',
        };
    }

    /**
     * The payment state an order lands in the moment this method is agreed.
     *
     * Cash is settled as far as this application is concerned — there is
     * nothing more for it to do, and somebody will take the money at the door.
     * A card link is not paid until Stripe says so, and `link_sent` is set when
     * the text actually goes out rather than here.
     */
    public function initialPaymentStatus(): PaymentStatus
    {
        return match ($this) {
            self::CardLink => PaymentStatus::Unpaid,
            self::Cash => PaymentStatus::CashOnCollection,
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return $this->color();
    }
}
