<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\ModifierKind;
use App\Support\Money;

/**
 * A modifier with its price resolved for the item it was applied to.
 *
 * `priceDelta` is per single unit of the item and already accounts for the
 * per-item override, so it may differ from the Modifier row's own delta. It is
 * copied verbatim into the order snapshot.
 */
final readonly class PricedModifier
{
    public function __construct(
        public ?int $modifierId,
        public ?string $modifierSlug,
        public ?int $modifierGroupId,
        public ?string $groupName,
        public string $name,
        public ModifierKind $kind,
        public int $priceDelta,
        public int $quantity,
        public string $currency,
    ) {}

    /** Total contribution to one unit of the item. */
    public function total(): int
    {
        return $this->priceDelta * $this->quantity;
    }

    public function money(): Money
    {
        return Money::of($this->total(), $this->currency);
    }

    public function isFree(): bool
    {
        return $this->total() === 0;
    }

    /**
     * How the agent says this when reading the order back.
     *
     * Free modifiers are read without a price, because "no onions, zero pounds"
     * is not something anyone says.
     */
    public function spoken(): string
    {
        $name = $this->quantity > 1
            ? sprintf('%d× %s', $this->quantity, $this->name)
            : $this->name;

        $phrase = match ($this->kind) {
            ModifierKind::Removal => sprintf('no %s', mb_strtolower($name)),
            ModifierKind::Swap => sprintf('%s instead', $name),
            default => $name,
        };

        if ($this->isFree()) {
            return $phrase;
        }

        return sprintf('%s, %s', $phrase, Money::of($this->total(), $this->currency)->spoken());
    }

    public function ticketLabel(): string
    {
        return $this->kind->ticketPrefix().$this->name;
    }

    /**
     * The shape stored in OrderItem::modifiers_snapshot.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'modifier_id' => $this->modifierId,
            'modifier' => $this->modifierSlug,
            'modifier_group_id' => $this->modifierGroupId,
            'group_name' => $this->groupName,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'price_delta' => $this->priceDelta,
            'quantity' => $this->quantity,
        ];
    }
}
