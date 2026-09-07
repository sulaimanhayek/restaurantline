<?php

declare(strict_types=1);

namespace App\Services\Menu;

/**
 * The ranked candidates for one spoken phrase, and what to do about them.
 *
 * The three questions an agent needs answered are "did you find it", "are you
 * sure", and "do I need to ask which one" — so those are methods here rather
 * than thresholds sprinkled through the endpoint.
 */
final readonly class MenuMatchResult
{
    /**
     * @param  list<MenuMatch>  $matches  Ranked, best first.
     */
    public function __construct(
        public string $query,
        public string $normalisedQuery,
        public array $matches,
        public float $confidentThreshold,
        public float $ambiguityMargin,
    ) {}

    public function best(): ?MenuMatch
    {
        return $this->matches[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->matches === [];
    }

    /**
     * Two plausible candidates too close to separate.
     *
     * "Chicken burger" against a menu with three chicken burgers is the normal
     * case, not an error. The agent must ask which one rather than pick.
     */
    public function isAmbiguous(): bool
    {
        if (count($this->matches) < 2) {
            return false;
        }

        // Rounded before it is compared: scores come back to four decimal
        // places, and 1.0 - 0.92 is 0.08000000000000007, which would slip past
        // an ambiguity margin of exactly 0.08.
        $gap = round($this->matches[0]->confidence - $this->matches[1]->confidence, 4);

        return $gap <= $this->ambiguityMargin;
    }

    /**
     * Safe to add to the order without checking first?
     */
    public function isConfident(): bool
    {
        $best = $this->best();

        return $best !== null
            && $best->confidence >= $this->confidentThreshold
            && ! $this->isAmbiguous();
    }

    /**
     * The candidates worth reading out when the agent has to ask.
     *
     * @return list<MenuMatch>
     */
    public function alternatives(int $limit = 3): array
    {
        return array_slice($this->matches, 0, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAgentArray(): array
    {
        return [
            'query' => $this->query,
            'matches' => array_map(
                static fn (MenuMatch $match): array => $match->toAgentArray(),
                $this->matches,
            ),
            'confident' => $this->isConfident(),
            'ambiguous' => $this->isAmbiguous(),
        ];
    }
}
