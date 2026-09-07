<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

use App\Enums\GeocodeProvider;
use App\Support\Distance;
use App\Support\SpokenText;

/**
 * A deterministic offline geocoder.
 *
 * This is the default driver, and what the test suite uses. It knows a couple
 * of dozen streets around the seeded demo restaurant in east London and nothing
 * else, which is the point: `docker compose up` gives a forker a working
 * address flow with no Google account, no billing alarm, and no flakiness in
 * CI.
 *
 * The built-in table is chosen to break things rather than to flatter the code:
 *
 *  - three separate addresses on Brick Lane, so "Brick Lane" alone is genuinely
 *    ambiguous and the agent has to ask for a house number;
 *  - a flat that shares a street address with a house, so line 2 matters;
 *  - an address several kilometres outside the delivery radius;
 *  - an address whose postcode is right and whose street a caller will
 *    mispronounce.
 *
 * Tests can pass their own rows to the constructor. Nothing here reaches the
 * network, ever.
 */
final class FakeGeocoder implements Geocoder
{
    /**
     * Anything scoring below this is not a candidate at all. Distinct from the
     * configured minimum confidence, which decides what is offered to a caller.
     */
    private const FLOOR = 0.35;

    /**
     * @var list<array{line_1: string, line_2: string|null, city: string, postcode: string, lat: float, lon: float}>
     */
    private array $rows;

    /**
     * @param  list<array{line_1: string, line_2?: string|null, city?: string, postcode: string, lat: float, lon: float}>|null  $rows
     */
    public function __construct(?array $rows = null)
    {
        $this->rows = $rows === null ? self::defaultRows() : array_map(
            static fn (array $row): array => [
                'line_1' => $row['line_1'],
                'line_2' => $row['line_2'] ?? null,
                'city' => $row['city'] ?? 'London',
                'postcode' => $row['postcode'],
                'lat' => $row['lat'],
                'lon' => $row['lon'],
            ],
            $rows,
        );
    }

    /**
     * @param  array{lat: float, lon: float}|null  $near
     * @return list<GeocodeCandidate>
     */
    public function geocode(string $query, ?array $near = null, int $limit = 5): array
    {
        $normalised = SpokenText::normalise($query);

        if ($normalised === '') {
            return [];
        }

        $scored = [];

        foreach ($this->rows as $row) {
            $score = $this->score($normalised, $row);

            if ($score < self::FLOOR) {
                continue;
            }

            $scored[] = ['score' => $score, 'row' => $row];
        }

        // Ties are broken by proximity to the restaurant when we have a point
        // to measure from, and stably by postcode when we do not. A geocoder
        // that returns "Brick Lane" in a different order on every call makes
        // an intermittently wrong agent, which is far worse to debug than a
        // consistently wrong one.
        usort($scored, function (array $a, array $b) use ($near): int {
            $byScore = $b['score'] <=> $a['score'];

            if ($byScore !== 0) {
                return $byScore;
            }

            if ($near !== null) {
                $byDistance = Distance::haversineMetres($near['lat'], $near['lon'], $a['row']['lat'], $a['row']['lon'])
                    <=> Distance::haversineMetres($near['lat'], $near['lon'], $b['row']['lat'], $b['row']['lon']);

                if ($byDistance !== 0) {
                    return $byDistance;
                }
            }

            return strcmp($a['row']['line_1'], $b['row']['line_1']);
        });

        return array_map(
            fn (array $entry): GeocodeCandidate => $this->toCandidate($entry['row'], $entry['score']),
            array_slice($scored, 0, $limit),
        );
    }

    /**
     * Token overlap in both directions.
     *
     * `matched` is how much of what the caller said this address accounts for —
     * a caller who says a house number the address does not have should not get
     * a confident match. `coverage` is how much of the address the caller
     * actually gave, which is what separates "42 Cheshire Street E2 6EH" from a
     * bare street name.
     *
     * @param  array{line_1: string, line_2: string|null, city: string, postcode: string, lat: float, lon: float}  $row
     */
    private function score(string $normalisedQuery, array $row): float
    {
        $queryTokens = $this->tokens($normalisedQuery);

        if ($queryTokens === []) {
            return 0.0;
        }

        $addressTokens = $this->tokens(SpokenText::normalise(implode(' ', array_filter([
            $row['line_1'],
            $row['line_2'],
            $row['city'],
            $row['postcode'],
        ]))));

        $common = array_intersect($queryTokens, $addressTokens);

        $matched = count($common) / count($queryTokens);
        $coverage = count($common) / max(1, count($addressTokens));

        $score = 0.65 * $matched + 0.35 * $coverage;

        // A full postcode is the one thing a caller can give that identifies a
        // building outright, so it outranks everything else — including a
        // street name they got wrong.
        $postcodeTokens = $this->tokens(SpokenText::normalise($row['postcode']));

        if ($postcodeTokens !== [] && array_intersect($postcodeTokens, $queryTokens) === $postcodeTokens) {
            $score = max($score, 0.95);
        }

        return round($score, 4);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        return array_values(array_unique(array_filter(
            explode(' ', $text),
            static fn (string $token): bool => $token !== '',
        )));
    }

    /**
     * @param  array{line_1: string, line_2: string|null, city: string, postcode: string, lat: float, lon: float}  $row
     */
    private function toCandidate(array $row, float $score): GeocodeCandidate
    {
        $formatted = implode(', ', array_filter([
            $row['line_2'],
            $row['line_1'],
            $row['city'],
            $row['postcode'],
        ]));

        return new GeocodeCandidate(
            formattedAddress: $formatted.', UK',
            latitude: $row['lat'],
            longitude: $row['lon'],
            confidence: $score,
            provider: GeocodeProvider::Fake,
            line1: $row['line_1'],
            line2: $row['line_2'],
            city: $row['city'],
            postcode: $row['postcode'],
            country: 'GB',
            // Deterministic and obviously synthetic. If one of these ever turns
            // up in a production `place_id` column, the fake driver was live.
            placeId: 'fake-'.substr(md5($formatted), 0, 12),
        );
    }

    /**
     * @return list<array{line_1: string, line_2: string|null, city: string, postcode: string, lat: float, lon: float}>
     */
    private static function defaultRows(): array
    {
        return [
            // The restaurant itself, so "are you on Brick Lane?" resolves.
            ['line_1' => '118 Brick Lane', 'line_2' => null, 'city' => 'London', 'postcode' => 'E1 6RL', 'lat' => 51.5218, 'lon' => -0.0715],

            // Two more on the same street. "Brick Lane" on its own must come
            // back ambiguous rather than picking one.
            ['line_1' => '7 Brick Lane', 'line_2' => null, 'city' => 'London', 'postcode' => 'E1 6PU', 'lat' => 51.5195, 'lon' => -0.0722],
            ['line_1' => '210 Brick Lane', 'line_2' => null, 'city' => 'London', 'postcode' => 'E1 6SA', 'lat' => 51.5248, 'lon' => -0.0708],

            // A house and a flat at the same street address.
            ['line_1' => '42 Cheshire Street', 'line_2' => null, 'city' => 'London', 'postcode' => 'E2 6EH', 'lat' => 51.5240, 'lon' => -0.0705],
            ['line_1' => '42 Cheshire Street', 'line_2' => 'Flat B', 'city' => 'London', 'postcode' => 'E2 6EH', 'lat' => 51.5240, 'lon' => -0.0705],

            ['line_1' => '3 Hanbury Street', 'line_2' => null, 'city' => 'London', 'postcode' => 'E1 6QR', 'lat' => 51.5202, 'lon' => -0.0738],

            // Callers say "Fornier", "Fournay", "Fourner". A good test of how
            // the matcher behaves when the street name arrives mangled.
            ['line_1' => '15 Fournier Street', 'line_2' => null, 'city' => 'London', 'postcode' => 'E1 6QE', 'lat' => 51.5190, 'lon' => -0.0745],

            ['line_1' => '88 Bethnal Green Road', 'line_2' => null, 'city' => 'London', 'postcode' => 'E2 6DG', 'lat' => 51.5262, 'lon' => -0.0688],
            ['line_1' => '24 Columbia Road', 'line_2' => null, 'city' => 'London', 'postcode' => 'E2 7RG', 'lat' => 51.5296, 'lon' => -0.0672],
            ['line_1' => '91 Mare Street', 'line_2' => null, 'city' => 'London', 'postcode' => 'E8 4RU', 'lat' => 51.5385, 'lon' => -0.0570],
            ['line_1' => '5 Roman Road', 'line_2' => null, 'city' => 'London', 'postcode' => 'E3 5ES', 'lat' => 51.5300, 'lon' => -0.0330],

            // Comfortably outside a 5km radius. Somebody will order from here.
            ['line_1' => '14 High Street', 'line_2' => null, 'city' => 'Croydon', 'postcode' => 'CR0 1QG', 'lat' => 51.3762, 'lon' => -0.0982],
        ];
    }
}
