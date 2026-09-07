<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Great-circle distance between two points, in metres.
 *
 * restaurantline deliberately does not require PostGIS. A takeaway delivers
 * within a few kilometres, and over that range the haversine formula on plain
 * `latitude`/`longitude` columns is accurate to within a metre or two — far
 * inside the error of the geocoder that produced the coordinates in the first
 * place. Adding a spatial extension to shave off that rounding would cost every
 * forker a harder database setup on their first hour with the repo.
 *
 * What this is not: driving distance. A river between two points a kilometre
 * apart makes them a ten-minute drive apart. If a client's delivery area is
 * shaped by geography rather than radius, swap this for a routing API — the
 * calculation is confined to this class and DeliveryFeeRule reads only metres.
 *
 * @see docs/DECISIONS.md #0012
 */
final class Distance
{
    /** Mean radius of the Earth, in metres (IUGG). */
    private const EARTH_RADIUS_METRES = 6_371_008.8;

    public static function haversineMetres(
        float $fromLat,
        float $fromLon,
        float $toLat,
        float $toLon,
    ): int {
        $latDelta = deg2rad($toLat - $fromLat);
        $lonDelta = deg2rad($toLon - $fromLon);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($lonDelta / 2) ** 2;

        return (int) round(self::EARTH_RADIUS_METRES * 2 * asin(min(1.0, sqrt($a))));
    }

    /**
     * @param  array{lat: float, lon: float}  $from
     * @param  array{lat: float, lon: float}  $to
     */
    public static function between(array $from, array $to): int
    {
        return self::haversineMetres($from['lat'], $from['lon'], $to['lat'], $to['lon']);
    }

    /**
     * Metres rendered the way a person says them: "about 1.2 kilometres".
     *
     * Used when the agent has to explain that an address is out of range;
     * "2847 metres" is a sentence no human would utter. Nor is "about 1000
     * metres", which is why the rounding happens before the unit is chosen.
     */
    public static function spoken(int $metres): string
    {
        $rounded = $metres < 1000 ? (int) round($metres / 50) * 50 : $metres;

        if ($rounded < 1000) {
            return sprintf('about %d metres', $rounded);
        }

        $kilometres = rtrim(rtrim(number_format($rounded / 1000, 1), '0'), '.');

        return sprintf('about %s %s', $kilometres, $kilometres === '1' ? 'kilometre' : 'kilometres');
    }
}
