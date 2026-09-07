<?php

declare(strict_types=1);

namespace App\Services\Menu;

use App\Models\Modifier;
use App\Models\ModifierGroup;

/**
 * A modifier candidate for something a caller said about an item.
 *
 * Carries the group because the agent needs it to enforce "pick exactly one":
 * choosing Large means un-choosing Medium, and only the group says which
 * choices are mutually exclusive.
 */
final readonly class ModifierMatch
{
    public function __construct(
        public Modifier $modifier,
        public ModifierGroup $group,
        public float $confidence,
        public string $matchedTerm,
        public int $priceDelta,
        public bool $isAvailable,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAgentArray(): array
    {
        return [
            'id' => $this->modifier->id,
            'name' => $this->modifier->name,
            'kind' => $this->modifier->kind->value,
            'group' => $this->group->name,
            'group_id' => $this->group->id,
            'price_delta' => $this->priceDelta,
            'available' => $this->isAvailable,
            'confidence' => round($this->confidence, 3),
        ];
    }
}
