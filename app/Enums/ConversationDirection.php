<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ConversationDirection: string implements HasLabel
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'Inbound',
            self::Outbound => 'Outbound',
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
}
