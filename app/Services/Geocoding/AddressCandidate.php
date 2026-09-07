<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

use App\Support\Distance;

/**
 * A geocoded address measured against the restaurant that would deliver to it.
 *
 * GeocodeCandidate says where somewhere is; this says whether it is somewhere
 * we go.
 */
final readonly class AddressCandidate
{
    public function __construct(
        public GeocodeCandidate $geocode,
        public int $distanceMetres,
        public bool $withinDeliveryRadius,
    ) {}

    public function confidence(): float
    {
        return $this->geocode->confidence;
    }

    public function spoken(): string
    {
        return $this->geocode->spoken();
    }

    /**
     * The columns an Address row is filled from, distance included.
     *
     * `verified_at` is deliberately absent. An address becomes verified only
     * when the caller has heard it read back and said yes, and that happens at
     * the order-creation endpoint — not here, and not as a side effect of
     * having been found.
     *
     * @return array<string, mixed>
     */
    public function toAddressAttributes(): array
    {
        return $this->geocode->toAddressAttributes() + [
            'distance_metres' => $this->distanceMetres,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAgentArray(): array
    {
        return [
            'address' => $this->geocode->spoken(),
            'formatted_address' => $this->geocode->formattedAddress,
            'postcode' => $this->geocode->postcode,
            'distance_metres' => $this->distanceMetres,
            'distance_spoken' => Distance::spoken($this->distanceMetres),
            'within_delivery_area' => $this->withinDeliveryRadius,
            'confidence' => round($this->geocode->confidence, 3),
        ];
    }
}
