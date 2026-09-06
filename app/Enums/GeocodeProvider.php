<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which geocoder produced an address.
 *
 * Stored on every Address so that when a delivery goes to the wrong street you
 * can tell whether the fake driver was accidentally live in production.
 */
enum GeocodeProvider: string
{
    case Google = 'google';

    /** The deterministic offline geocoder used in development and tests. */
    case Fake = 'fake';

    /** Typed in by a human in the dashboard; not geocoded. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google Geocoding',
            self::Fake => 'Fake (offline)',
            self::Manual => 'Entered manually',
        };
    }
}
