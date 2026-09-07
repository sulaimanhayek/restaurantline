<?php

declare(strict_types=1);

namespace App\Services\Menu;

use App\Models\MenuItem;

/**
 * One candidate item for something a caller said.
 *
 * Unavailable items are still returned, flagged. An agent that says "we've run
 * out of the halloumi burger, sorry" is doing its job; one that says "I don't
 * know what that is" about a dish printed on the menu is not, and the caller
 * hangs up.
 */
final readonly class MenuMatch
{
    public function __construct(
        public MenuItem $item,
        public float $confidence,
        public string $matchedTerm,
        public bool $isAvailableNow,
        public ?string $unavailableReason = null,
    ) {}

    public function isExact(): bool
    {
        return $this->confidence >= 0.999;
    }

    /**
     * The shape the /menu/search tool returns for one candidate.
     *
     * @return array<string, mixed>
     */
    public function toAgentArray(): array
    {
        return [
            'id' => $this->item->id,
            'name' => $this->item->name,
            'description' => $this->item->description,
            'price' => $this->item->price,
            'price_spoken' => $this->item->money()->spoken(),
            'available' => $this->isAvailableNow,
            'unavailable_reason' => $this->unavailableReason,
            'confidence' => round($this->confidence, 3),
        ];
    }
}
