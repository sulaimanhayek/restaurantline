<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Modifier;

/**
 * One modifier chosen for a cart line, before pricing.
 *
 * `quantity` exists for the "double cheese" case. It is almost always 1, and is
 * meaningless for a removal — you cannot leave the onions out twice.
 */
final readonly class CartModifier
{
    public function __construct(
        public Modifier $modifier,
        public int $quantity = 1,
    ) {}
}
