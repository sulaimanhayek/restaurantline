<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Whether a modifier group accepts one choice or several.
 *
 * This is separate from min/max selections on purpose: `single` tells the agent
 * to phrase the question as "small, medium or large?" while `multi` tells it to
 * phrase it as "anything else on that?".
 */
enum ModifierGroupSelectionType: string implements HasLabel
{
    /** Exactly one option — sizes, cooking temperature, choice of base. */
    case Single = 'single';

    /** Any number within min/max — toppings, extras, removals. */
    case Multi = 'multi';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Choose one',
            self::Multi => 'Choose several',
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
