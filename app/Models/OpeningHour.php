<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\SpokenTime;
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
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
        ];
    }

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * How this window reads out loud: "5pm to 10:30pm".
     */
    public function spoken(): string
    {
        return SpokenTime::range($this->opens_at, $this->closes_at);
    }

    public function dayName(): string
    {
        return CarbonImmutable::create(2024, 1, 7)->addDays($this->day_of_week)->format('l');
    }
}
