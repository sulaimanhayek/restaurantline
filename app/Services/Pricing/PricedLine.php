<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Support\Money;

/**
 * One priced order line.
 *
 * Every field a persisted OrderItem needs is here, already resolved. Order
 * creation copies these across rather than recomputing, so the number the
 * caller heard is the number stored.
 */
final readonly class PricedLine
{
    /**
     * @param  list<PricedModifier>  $modifiers
     * @param  int  $unitPrice  The menu price, before modifiers.
     * @param  int  $modifiersTotal  Sum of modifier deltas for ONE unit.
     * @param  int  $lineTotal  (unitPrice + modifiersTotal) × quantity.
     */
    public function __construct(
        public ?int $menuItemId,
        public ?string $menuItemSlug,
        public string $name,
        public ?string $description,
        public ?string $sku,
        public int $unitPrice,
        public int $modifiersTotal,
        public int $quantity,
        public int $lineTotal,
        public array $modifiers,
        public ?string $notes,
        public string $currency,
        public int $sortOrder = 0,
    ) {}

    /** The price of one unit with its modifiers applied. */
    public function unitPriceWithModifiers(): int
    {
        return $this->unitPrice + $this->modifiersTotal;
    }

    public function lineTotalMoney(): Money
    {
        return Money::of($this->lineTotal, $this->currency);
    }

    /**
     * How the agent reads this line back to the caller.
     *
     * "Two Ember Chicken Burgers with no onions and extra cheese, 17 pounds 98".
     * The modifiers are spoken because a caller who hears only the item name has
     * no way to catch the mistake that matters most.
     */
    public function spoken(): string
    {
        $line = $this->quantity > 1
            ? sprintf('%d %s', $this->quantity, $this->name)
            : $this->name;

        $modifiers = array_map(
            static fn (PricedModifier $modifier): string => $modifier->spoken(),
            $this->modifiers,
        );

        if ($modifiers !== []) {
            $line .= ' with '.self::joinSpoken($modifiers);
        }

        return sprintf('%s, %s', $line, $this->lineTotalMoney()->spoken());
    }

    /**
     * The value stored in OrderItem::modifiers_snapshot — enough to render a
     * ticket without touching the modifiers table.
     *
     * @return list<array<string, mixed>>
     */
    public function toSnapshot(): array
    {
        return array_map(
            static fn (PricedModifier $modifier): array => $modifier->toSnapshot(),
            $this->modifiers,
        );
    }

    /**
     * "a, b and c" — an Oxford-comma-free list, because it is being spoken.
     *
     * @param  list<string>  $parts
     */
    private static function joinSpoken(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }
}
