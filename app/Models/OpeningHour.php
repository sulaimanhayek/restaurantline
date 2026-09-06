<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OpeningHourFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One contiguous serving window in the recurring weekly pattern.
 *
 * A restaurant that shuts between lunch and dinner has two rows for that
 * weekday rather than one row with a gap, which is why nothing here is unique
 * on (restaurant_id, day_of_week).
 *
 * @property int $id
 * @property int $restaurant_id
 * @property int $day_of_week 0 = Sunday, matching Carbon::dayOfWeek
 * @property string $opens_at Local wall-clock "HH:MM:SS"
 * @property string $closes_at Local wall-clock "HH:MM:SS"
 * @property bool $closes_next_day
 * @property string|null $label
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Restaurant $restaurant
 *
 * @method static OpeningHourFactory factory($count = null, $state = [])
 */
class OpeningHour extends Model
{
    /** @use HasFactory<OpeningHourFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'closes_next_day' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * How this window reads out loud: "half past eleven to eleven at night" is
     * beyond us, but "11:30 to 23:00" is unambiguous and speech engines handle
     * it well.
     */
    public function spoken(): string
    {
        return sprintf('%s to %s', $this->spokenTime($this->opens_at), $this->spokenTime($this->closes_at));
    }

    /**
     * "17:00" reads as "seventeen hundred" or worse. Nobody on a takeaway line
     * says that, so shifts are spoken the way a person answering the phone
     * would say them: "5pm", "half past ten", "midnight".
     */
    private function spokenTime(string $time): string
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $time));

        if ($hour === 0 && $minute === 0) {
            return 'midnight';
        }

        if ($hour === 12 && $minute === 0) {
            return 'midday';
        }

        $meridiem = $hour < 12 ? 'am' : 'pm';
        $twelveHour = $hour % 12 === 0 ? 12 : $hour % 12;

        return $minute === 0
            ? sprintf('%d%s', $twelveHour, $meridiem)
            : sprintf('%d:%02d%s', $twelveHour, $minute, $meridiem);
    }

    public function dayName(): string
    {
        return CarbonImmutable::create(2024, 1, 7)->addDays($this->day_of_week)->format('l');
    }
}
