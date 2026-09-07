<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

/**
 * Turning spoken words into coordinates.
 *
 * One method, because that is all the application needs and every extra method
 * on this interface is one more thing a forker swapping in their own provider
 * has to implement. Bind an implementation in AppServiceProvider; the fake is
 * the default so the test suite and `docker compose up` never touch a network.
 */
interface Geocoder
{
    /**
     * Candidates for a spoken address, best first.
     *
     * Returns an empty array when nothing plausible was found — never throws
     * for a bad address, because "I couldn't find that, could you say the
     * postcode?" is a normal turn in a phone call, not an exception.
     *
     * @param  array{lat: float, lon: float}|null  $near  Bias results toward
     *                                                    this point. Callers
     *                                                    pass the restaurant.
     * @return list<GeocodeCandidate>
     */
    public function geocode(string $query, ?array $near = null, int $limit = 5): array;
}
