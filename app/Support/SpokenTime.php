<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Wall-clock times, said the way someone answering a phone says them.
 *
 * "17:00" comes out of a speech engine as "seventeen hundred", "seventeen zero
 * zero", or occasionally "seventeen colon zero zero". None of those is how a
 * person tells you when the kitchen shuts. Opening hours and menu availability
 * windows both read times aloud, so the conversion lives here rather than in
 * either model.
 */
final class SpokenTime
{
    /**
     * @param  string  $time  Local wall-clock, "HH:MM" or "HH:MM:SS".
     */
    public static function of(string $time): string
    {
        $parts = explode(':', $time);
        $hour = (int) $parts[0];
        $minute = (int) ($parts[1] ?? 0);

        if ($hour === 0 && $minute === 0) {
            return 'midnight';
        }

        if ($hour === 12 && $minute === 0) {
            return 'midday';
        }

        $meridiem = $hour < 12 || $hour >= 24 ? 'am' : 'pm';
        $twelveHour = $hour % 12 === 0 ? 12 : $hour % 12;

        return $minute === 0
            ? sprintf('%d%s', $twelveHour, $meridiem)
            : sprintf('%d:%02d%s', $twelveHour, $minute, $meridiem);
    }

    /**
     * "5pm to 10:30pm".
     */
    public static function range(string $from, string $until): string
    {
        return sprintf('%s to %s', self::of($from), self::of($until));
    }
}
