<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\MenuItem;

/**
 * One line a caller has asked for, before pricing.
 *
 * This is what the agent's words become: a menu item, how many, and which
 * modifiers. Nothing here carries a price — prices are resolved by
 * PricingService against the live menu, so the agent can never quote a number
 * the restaurant is not charging.
 */
final readonly class CartLine
{
    /**
     * @param  list<CartModifier>  $modifiers
     */
    public function __construct(
        public MenuItem $item,
        public int $quantity = 1,
        public array $modifiers = [],
        public ?string $notes = null,
    ) {}
}
