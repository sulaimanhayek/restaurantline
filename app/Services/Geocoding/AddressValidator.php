<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

use App\Models\Restaurant;
use App\Support\Distance;

/**
 * Everything between "what the caller said" and "an address we can deliver to".
 *
 * Address handling is the highest-risk step in a voice order — higher than
 * pricing, higher than the menu — because it is the one mistake that costs the
 * restaurant a whole order's food and an hour of a driver's time, and the one
 * the caller cannot see coming. Three rules follow from that, and they are
 * enforced here rather than left to a prompt:
 *
 *  1. A candidate below the configured confidence is never offered at all.
 *  2. Candidates that sound alike are reported as ambiguous, so the agent asks
 *     rather than guesses.
 *  3. Nothing is marked verified here. Verification means a human heard the
 *     address read back and agreed; that happens at order creation, and a
 *     delivery order with an unverified address is refused.
 */
final readonly class AddressValidator
{
    public function __construct(private Geocoder $geocoder) {}

    public function validate(Restaurant $restaurant, string $spoken): AddressValidationResult
    {
        $minimum = (float) config('restaurantline.geocoder.minimum_confidence', 0.4);
        $limit = (int) config('restaurantline.geocoder.max_candidates', 3);

        $origin = $restaurant->coordinates();

        // Ask for more than we will return: filtering by confidence after the
        // fact would otherwise throw away the good candidates behind a run of
        // weak ones.
        $found = $this->geocoder->geocode($spoken, $origin, max($limit * 2, 5));

        $candidates = [];

        foreach ($found as $candidate) {
            if ($candidate->confidence < $minimum) {
                continue;
            }

            $candidates[] = $this->measure($restaurant, $candidate, $origin);

            if (count($candidates) === $limit) {
                break;
            }
        }

        return new AddressValidationResult(
            spoken: $spoken,
            candidates: $candidates,
            deliveryRadiusMetres: $restaurant->delivery_radius_metres,
            confidentThreshold: (float) config('restaurantline.geocoder.confident_threshold', 0.8),
            ambiguityMargin: (float) config('restaurantline.geocoder.ambiguity_margin', 0.05),
        );
    }

    /**
     * How far a candidate is, and whether that is too far.
     *
     * A restaurant with no coordinates of its own cannot answer either
     * question. Rather than inventing a distance, we report zero and treat the
     * address as deliverable — the operator has not told us where they are, and
     * silently refusing every order until they do would be a worse failure than
     * accepting one we should have questioned.
     *
     * @param  array{lat: float, lon: float}|null  $origin
     */
    private function measure(Restaurant $restaurant, GeocodeCandidate $candidate, ?array $origin): AddressCandidate
    {
        if ($origin === null) {
            return new AddressCandidate($candidate, 0, true);
        }

        $distance = Distance::between($origin, $candidate->coordinates());

        return new AddressCandidate(
            geocode: $candidate,
            distanceMetres: $distance,
            withinDeliveryRadius: $distance <= $restaurant->delivery_radius_metres,
        );
    }
}
