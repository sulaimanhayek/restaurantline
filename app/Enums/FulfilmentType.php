<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the customer receives the order.
 *
 * This drives whether an address is required at all: collection orders have no
 * address, and the order-creation endpoint rejects a delivery order without a
 * verified one.
 */
enum FulfilmentType: string
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
}
