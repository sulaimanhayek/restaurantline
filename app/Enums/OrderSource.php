<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an order came from.
 *
 * Worth keeping honest: comparing error rates between `voice` and `dashboard`
 * orders is the quickest way to see whether the agent is actually working.
 */
enum OrderSource: string
{
    /** Taken by the ElevenLabs agent over the phone. */
    case Voice = 'voice';

    /** Entered by a member of staff in the Filament dashboard. */
    case Dashboard = 'dashboard';

    /** Placed through a web ordering front end. Not built here; reserved. */
    case Web = 'web';

    public function label(): string
    {
        return match ($this) {
            self::Voice => 'Phone (agent)',
            self::Dashboard => 'Dashboard',
            self::Web => 'Web',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Voice => 'heroicon-o-phone',
            self::Dashboard => 'heroicon-o-computer-desktop',
            self::Web => 'heroicon-o-globe-alt',
        };
    }
}
