<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\UtcDateTime;
use Carbon\CarbonImmutable;
use Database\Factories\OpeningHourOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A specific date that departs from the weekly pattern.
 *
 * An override wins outright for its date: if any override rows exist for a
 * date, the weekly pattern is ignored entirely for that date. That rule is
 * simpler to reason about — and to explain to an operator — than trying to
 * merge the two.
 *
 * @property int $id
 * @property int $restaurant_id
 * @property CarbonImmutable $date
 * @property bool $is_closed
 * @property string|null $opens_at
 * @property string|null $closes_at
 * @property bool $closes_next_day
 * @property string|null $reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Restaurant $restaurant
 *
 * @method static OpeningHourOverrideFactory factory($count = null, $state = [])
 */
class OpeningHourOverride extends Model
{
    /** @use HasFactory<OpeningHourOverrideFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'is_closed' => 'boolean',
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
}
