<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

use App\Enums\GeocodeProvider;

/**
 * One address a geocoder thinks the caller might have meant.
 *
 * `confidence` is normalised to 0.0–1.0 across providers so the threshold in
 * config means the same thing whichever one is configured. Each driver is
 * responsible for mapping its own vocabulary onto that scale, and for saying so
 * in a comment — a silent difference here is the kind of thing that sends food
 * to the wrong street.
 */
final readonly class GeocodeCandidate
{
    public function __construct(
        public string $formattedAddress,
        public float $latitude,
        public float $longitude,
        public float $confidence,
        public GeocodeProvider $provider,
        public ?string $line1 = null,
        public ?string $line2 = null,
        public ?string $city = null,
        public ?string $postcode = null,
        public ?string $country = null,
        public ?string $placeId = null,
    ) {}

    /**
     * @return array{lat: float, lon: float}
     */
    public function coordinates(): array
    {
        return ['lat' => $this->latitude, 'lon' => $this->longitude];
    }

    /**
     * How the agent reads this back for confirmation.
     *
     * House number, street and postcode — the parts a person checks against.
     * Reading out a county and a country to someone who has just told you where
     * they live is how you lose them.
     */
    public function spoken(): string
    {
        $parts = array_filter(
            [$this->line1, $this->line2, $this->city, $this->postcode],
            static fn (?string $part): bool => $part !== null && trim($part) !== '',
        );

        return $parts === [] ? $this->formattedAddress : implode(', ', $parts);
    }

    /**
     * The columns an Address row is filled from.
     *
     * @return array<string, mixed>
     */
    public function toAddressAttributes(): array
    {
        return [
            'formatted_address' => $this->formattedAddress,
            'line_1' => $this->line1,
            'line_2' => $this->line2,
            'city' => $this->city,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'geocode_confidence' => $this->confidence,
            'geocode_provider' => $this->provider,
            'place_id' => $this->placeId,
        ];
    }
}
