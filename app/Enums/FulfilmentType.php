<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How the customer receives the order.
 *
 * This drives whether an address is required at all: collection orders have no
 * address, and the order-creation endpoint rejects a delivery order without a
 * verified one.
 */
enum FulfilmentType: string implements HasLabel
{
    case Delivery = 'delivery';
    case Collection = 'collection';

    public function label(): string
    {
        return match ($this) {
            self::Delivery => 'Delivery',
            self::Collection => 'Collection',
        };
    }

    /**
     * How the agent should say this out loud.
     */
    public function spoken(): string
    {
        return match ($this) {
            self::Delivery => 'delivery',
            self::Collection => 'collection',
        };
    }

    public function requiresAddress(): bool
    {
        return $this === self::Delivery;
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
