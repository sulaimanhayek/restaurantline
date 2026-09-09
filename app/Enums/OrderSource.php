<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Where an order came from.
 *
 * Worth keeping honest: comparing error rates between `voice` and `dashboard`
 * orders is the quickest way to see whether the agent is actually working.
 */
enum OrderSource: string implements HasIcon, HasLabel
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

    public function getIcon(): string
    {
        return $this->icon();
    }
}
