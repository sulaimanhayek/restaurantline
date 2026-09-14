<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What happened to one outbound text.
 *
 * Deliberately short of the provider's full lifecycle. Twilio distinguishes
 * queued, sending, sent, delivered, undelivered and failed, and knowing which
 * of those a message reached requires a status-callback webhook this repo does
 * not ship. Recording states we cannot actually observe would be a dashboard
 * that lies: `sent` here means the provider accepted it, which is the last
 * thing this application genuinely knows.
 */
enum SmsStatus: string implements HasColor, HasLabel
{
    /** Written down, not yet handed to a provider. */
    case Queued = 'queued';

    /** The provider accepted it. Not proof it arrived on a handset. */
    case Sent = 'sent';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Sent => 'success',
            self::Failed => 'danger',
        };
    }

    /**
     * Did a provider take this message off our hands?
     *
     * The question anything downstream actually asks — whether a payment link
     * has been put in front of the customer, whether a resend is worth
     * offering — and phrased as a method so that adding `delivered` later,
     * once there is a status webhook to populate it, is one line here rather
     * than a search for `=== SmsStatus::Sent`.
     */
    public function wasSent(): bool
    {
        return $this === self::Sent;
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
