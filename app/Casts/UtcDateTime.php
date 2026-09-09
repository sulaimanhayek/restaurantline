<?php

declare(strict_types=1);

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A datetime column that always stores the instant, never the wall clock.
 *
 * Every timestamp column in this schema is `timestamp without time zone`, which
 * means the database holds bare digits and the application supplies the
 * convention that they are UTC. Laravel's own `datetime` cast does not enforce
 * that convention on the way in: it formats whatever Carbon it is handed,
 * offset and all, and throws the offset away.
 *
 * Most of the time that is harmless, because `now()` is UTC and almost
 * everything is written from `now()`. It stops being harmless the moment a
 * restaurant-local time is stored — and this application deals in restaurant
 * local time constantly, because opening hours, prep estimates and everything
 * a caller is told are all local by nature. A ready time of 16:00 in London
 * went into the column as the digits "16:00" and came back out as 16:00 UTC,
 * an hour late: the order said five o'clock, the kitchen display would have
 * counted down to the wrong minute, and nothing anywhere threw an error.
 *
 * So the conversion happens here, once, rather than at each of the places a
 * local time meets a column. Reading is the same convention in reverse.
 *
 * Deliberately not applied to `date` columns: converting a local midnight to
 * UTC moves it to 23:00 the day before, and a date column would then store the
 * wrong day. OpeningHourOverride::$date keeps the plain `immutable_date` cast.
 *
 * @implements CastsAttributes<CarbonImmutable, DateTimeInterface|string|int>
 *
 * @see docs/DECISIONS.md #0021
 */
final class UtcDateTime implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return CarbonImmutable::createFromTimestamp((int) $value, 'UTC');
        }

        // The column carries no offset, so one has to be assumed. Passing UTC
        // here is what makes the digits mean what this cast wrote. A driver
        // that does return an offset (a `timestamptz` column on a fork) keeps
        // its own, because PHP's parser prefers an explicit offset to the
        // timezone argument.
        return CarbonImmutable::parse((string) $value, 'UTC')->utc();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $date = match (true) {
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value),
            is_int($value) => CarbonImmutable::createFromTimestamp($value, 'UTC'),
            // A bare string has no offset and is taken at face value, matching
            // what the column holds. Anything with an offset keeps it.
            default => CarbonImmutable::parse((string) $value, 'UTC'),
        };

        return $date->utc()->format('Y-m-d H:i:s');
    }
}
