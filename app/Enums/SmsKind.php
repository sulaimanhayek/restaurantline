<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Why a text was sent.
 *
 * Kept as an enum rather than free text so the dashboard can filter by it and
 * so a fork adding "your driver is outside" has one obvious place to declare it
 * rather than inventing a string at the call site.
 */
enum SmsKind: string implements HasLabel
{
    /** Order details, and a payment link when there is one. */
    case OrderConfirmation = 'order_confirmation';

    /** The same link again, sent by hand from the dashboard. */
    case PaymentLink = 'payment_link';

    public function label(): string
    {
        return match ($this) {
            self::OrderConfirmation => 'Order confirmation',
            self::PaymentLink => 'Payment link',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
