<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

/**
 * What the geocoder made of a spoken address, and what the agent should do
 * about it.
 *
 * The four outcomes an agent has to tell apart are: nothing found, several
 * things found that sound alike, one thing found but out of range, and one
 * thing found that we deliver to. Each needs a different sentence, so each gets
 * a method here rather than a threshold comparison at the call site.
 */
final readonly class AddressValidationResult
{
    /**
     * @param  list<AddressCandidate>  $candidates  Ranked, best first.
     */
    public function __construct(
        public string $spoken,
        public array $candidates,
        public int $deliveryRadiusMetres,
        public float $confidentThreshold,
        public float $ambiguityMargin,
    ) {}

    public function best(): ?AddressCandidate
    {
        return $this->candidates[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->candidates === [];
    }

    /**
     * Two candidates too close to separate — "Brick Lane" with no house number.
     *
     * The difference is rounded before it is compared. Confidences are produced
     * to four decimal places, and without the rounding a pair exactly one
     * margin apart (1.0 and 0.95) comes out at 0.050000000000000044 and is
     * declared unambiguous by a floating-point artefact.
     */
    public function isAmbiguous(): bool
    {
        if (count($this->candidates) < 2) {
            return false;
        }

        $gap = round($this->candidates[0]->confidence() - $this->candidates[1]->confidence(), 4);

        return $gap <= $this->ambiguityMargin;
    }

    /**
     * Good enough to read back once and take a yes.
     */
    public function isConfident(): bool
    {
        $best = $this->best();

        return $best !== null
            && $best->confidence() >= $this->confidentThreshold
            && ! $this->isAmbiguous();
    }

    /**
     * Found, but too far to deliver to.
     *
     * Distinct from "not found": the caller gave a perfectly good address and
     * deserves to be told we do not come that far, not asked to repeat it.
     */
    public function isOutsideDeliveryArea(): bool
    {
        $best = $this->best();

        if ($best === null) {
            return false;
        }

        foreach ($this->candidates as $candidate) {
            if ($candidate->withinDeliveryRadius) {
                return false;
            }
        }

        return true;
    }

    /**
     * Candidates the restaurant would actually deliver to.
     *
     * @return list<AddressCandidate>
     */
    public function deliverable(): array
    {
        return array_values(array_filter(
            $this->candidates,
            static fn (AddressCandidate $candidate): bool => $candidate->withinDeliveryRadius,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toAgentArray(): array
    {
        return [
            'spoken_input' => $this->spoken,
            'candidates' => array_map(
                static fn (AddressCandidate $candidate): array => $candidate->toAgentArray(),
                $this->candidates,
            ),
            'found' => ! $this->isEmpty(),
            'confident' => $this->isConfident(),
            'ambiguous' => $this->isAmbiguous(),
            'outside_delivery_area' => $this->isOutsideDeliveryArea(),
        ];
    }
}
