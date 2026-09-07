<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

use App\Enums\GeocodeProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The Google Geocoding API.
 *
 * Switched on with GEOCODER_DRIVER=google. Read the address-handling section of
 * the README before you do: a geocoder that confidently returns the wrong
 * street is worse for a delivery business than one that returns nothing, and
 * the confidence mapping below is the only thing standing between the two.
 *
 * Failures are logged and swallowed. A caller on the phone gets "sorry, could
 * you give me your postcode?" — never a stack trace and never a stall while
 * something retries.
 */
final class GoogleGeocoder implements Geocoder
{
    /**
     * Google does not return a numeric score. It returns `location_type`, which
     * is a coarse statement of how the coordinates were derived, plus a
     * `partial_match` flag meaning "we could not match the whole thing you
     * gave us".
     *
     * ROOFTOP           — the building itself.
     * RANGE_INTERPOLATED— interpolated between two known house numbers. Usually
     *                     right, occasionally several doors out.
     * GEOMETRIC_CENTER  — the middle of a street or polygon. Not a house.
     * APPROXIMATE       — a district. Useless for delivery.
     *
     * The numbers below are the mapping onto the 0.0–1.0 scale the rest of the
     * application uses. The default GEOCODER_MIN_CONFIDENCE of 0.4 therefore
     * lets a geometric centre through as a candidate to confirm aloud, and
     * keeps a district out entirely.
     *
     * @var array<string, float>
     */
    private const LOCATION_TYPE_CONFIDENCE = [
        'ROOFTOP' => 0.95,
        'RANGE_INTERPOLATED' => 0.8,
        'GEOMETRIC_CENTER' => 0.5,
        'APPROXIMATE' => 0.25,
    ];

    /** A partial match means part of the address was ignored. */
    private const PARTIAL_MATCH_PENALTY = 0.25;

    /**
     * @param  array{lat: float, lon: float}|null  $near
     * @return list<GeocodeCandidate>
     */
    public function geocode(string $query, ?array $near = null, int $limit = 5): array
    {
        $key = config('restaurantline.geocoder.google.key');

        if (! is_string($key) || $key === '') {
            Log::warning('Google geocoder selected but GOOGLE_MAPS_API_KEY is empty; returning no candidates.');

            return [];
        }

        $parameters = array_filter([
            'address' => $query,
            'key' => $key,
            'region' => config('restaurantline.geocoder.region_bias'),
            'components' => ($country = config('restaurantline.geocoder.region_bias')) !== null
                ? 'country:'.$country
                : null,
            // Nudges results toward the restaurant rather than a same-named
            // street two hundred miles away.
            'bounds' => $near !== null ? $this->boundsAround($near) : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        try {
            $response = Http::timeout((int) config('restaurantline.geocoder.google.timeout', 8))
                ->get((string) config('restaurantline.geocoder.google.endpoint'), $parameters);
        } catch (ConnectionException $exception) {
            Log::warning('Geocoding request failed.', ['exception' => $exception->getMessage()]);

            return [];
        }

        if ($response->failed()) {
            Log::warning('Geocoding request returned an error.', ['status' => $response->status()]);

            return [];
        }

        /** @var array{status?: string, results?: array<int, array<string, mixed>>} $body */
        $body = $response->json() ?? [];
        $status = $body['status'] ?? 'UNKNOWN';

        // ZERO_RESULTS is an ordinary outcome, not a failure.
        if ($status !== 'OK') {
            if ($status !== 'ZERO_RESULTS') {
                Log::warning('Geocoding request was rejected.', ['status' => $status]);
            }

            return [];
        }

        $candidates = [];

        foreach (array_slice($body['results'] ?? [], 0, $limit) as $result) {
            $candidate = $this->toCandidate($result);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function toCandidate(array $result): ?GeocodeCandidate
    {
        $geometry = $result['geometry'] ?? null;
        $location = is_array($geometry) ? ($geometry['location'] ?? null) : null;

        if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
            return null;
        }

        $components = $this->components(is_array($result['address_components'] ?? null) ? $result['address_components'] : []);

        // A result with no location_type is a result Google could not place
        // precisely, so it gets the weakest score rather than the benefit of
        // the doubt.
        $locationType = is_string($geometry['location_type'] ?? null)
            ? $geometry['location_type']
            : 'APPROXIMATE';

        $confidence = self::LOCATION_TYPE_CONFIDENCE[$locationType] ?? 0.25;

        if (($result['partial_match'] ?? false) === true) {
            $confidence = max(0.0, $confidence - self::PARTIAL_MATCH_PENALTY);
        }

        $streetNumber = $components['street_number'] ?? null;
        $route = $components['route'] ?? null;

        return new GeocodeCandidate(
            formattedAddress: is_string($result['formatted_address'] ?? null) ? $result['formatted_address'] : '',
            latitude: (float) $location['lat'],
            longitude: (float) $location['lng'],
            confidence: round($confidence, 4),
            provider: GeocodeProvider::Google,
            line1: trim(implode(' ', array_filter([$streetNumber, $route]))) ?: null,
            line2: $components['subpremise'] ?? null,
            city: $components['postal_town'] ?? $components['locality'] ?? null,
            postcode: $components['postal_code'] ?? null,
            country: $components['country'] ?? null,
            placeId: is_string($result['place_id'] ?? null) ? $result['place_id'] : null,
        );
    }

    /**
     * Flatten Google's address_components into the handful of fields an order
     * actually needs.
     *
     * @param  array<int, mixed>  $components
     * @return array<string, string>
     */
    private function components(array $components): array
    {
        $flat = [];

        foreach ($components as $component) {
            if (! is_array($component) || ! is_array($component['types'] ?? null)) {
                continue;
            }

            foreach ($component['types'] as $type) {
                if (! is_string($type) || isset($flat[$type])) {
                    continue;
                }

                // Countries are stored as the two-letter code; everything else
                // as the name a person would recognise.
                $value = $type === 'country'
                    ? ($component['short_name'] ?? null)
                    : ($component['long_name'] ?? null);

                if (is_string($value) && $value !== '') {
                    $flat[$type] = $value;
                }
            }
        }

        return $flat;
    }

    /**
     * A box roughly 20km on a side around a point, in the `lat,lng|lat,lng`
     * format Google expects. Wide enough to cover any delivery radius a single
     * kitchen can serve, narrow enough to keep a same-named street in another
     * city out of the results.
     *
     * @param  array{lat: float, lon: float}  $near
     */
    private function boundsAround(array $near): string
    {
        $latDelta = 0.09;
        $lonDelta = 0.09 / max(0.1, cos(deg2rad($near['lat'])));

        return sprintf(
            '%F,%F|%F,%F',
            $near['lat'] - $latDelta,
            $near['lon'] - $lonDelta,
            $near['lat'] + $latDelta,
            $near['lon'] + $lonDelta,
        );
    }
}
