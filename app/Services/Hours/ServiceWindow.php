<?php

declare(strict_types=1);

namespace App\Services\Hours;

use App\Support\SpokenTime;
use Carbon\CarbonImmutable;

/**
 * One concrete open-to-close span, resolved onto an actual date.
 *
 * The stored `opening_hours` row is a recurring pattern — "Friday, 17:00 to
 * 02:00, closes next day". This is what that becomes once you pick a Friday:
 * two absolute instants in the restaurant's timezone, which is the only form
 * you can actually compare "now" against.
 */
final readonly class ServiceWindow
{
    public function __construct(
        public CarbonImmutable $opensAt,
        public CarbonImmutable $closesAt,
        public ?string $label = null,
    ) {}

    /**
     * Half-open on purpose: a window closing at 22:00 is shut at 22:00.
     *
     * A caller who rings at exactly closing time is ringing after closing time.
     */
    public function contains(CarbonImmutable $at): bool
    {
        return $at >= $this->opensAt && $at < $this->closesAt;
    }

    /**
     * "5pm to 10:30pm".
     */
    public function spoken(): string
    {
        return SpokenTime::range($this->opensAt->format('H:i'), $this->closesAt->format('H:i'));
    }
}
