<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a modifier does to the item.
 *
 * Modelling removals as first-class rows rather than free text is the single
 * decision that makes modifiers work properly here. "No onions" is a real
 * Modifier with its own spoken aliases, so it matches through exactly the same
 * code path as "Large" or "Extra cheese" — one matcher, one pricing rule, one
 * snapshot format — and it stays queryable, so you can actually answer "what do
 * callers ask us to leave out?".
 */
enum ModifierKind: string
{
    /** A choice within a set: "Large", "Thin crust". Usually in a `single` group. */
    case Option = 'option';

    /** Something added, normally at a cost: "Extra cheese". */
    case Addon = 'addon';

    /** Something left out: "No onions". Usually free. */
    case Removal = 'removal';

    /** One component exchanged for another: "Sweet potato fries instead". */
    case Swap = 'swap';

    public function label(): string
    {
        return match ($this) {
            self::Option => 'Option',
            self::Addon => 'Add-on',
            self::Removal => 'Removal',
            self::Swap => 'Swap',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Option => 'gray',
            self::Addon => 'success',
            self::Removal => 'danger',
            self::Swap => 'warning',
        };
    }

    /**
     * How this reads on a kitchen ticket.
     *
     * Removals are prefixed rather than merely listed, because a chef scanning
     * a ticket at speed must not mistake "onions" for an instruction to add
     * them.
     */
    public function ticketPrefix(): string
    {
        return match ($this) {
            self::Option => '',
            self::Addon => '+ ',
            self::Removal => 'NO ',
            self::Swap => '→ ',
        };
    }
}
