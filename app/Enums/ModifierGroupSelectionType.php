<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a modifier group accepts one choice or several.
 *
 * This is separate from min/max selections on purpose: `single` tells the agent
 * to phrase the question as "small, medium or large?" while `multi` tells it to
 * phrase it as "anything else on that?".
 */
enum ModifierGroupSelectionType: string
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
}
