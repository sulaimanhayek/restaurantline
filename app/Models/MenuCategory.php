<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\SpokenTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\MenuCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A section of the menu, optionally restricted to certain hours or days.
 *
 * The availability window is why /api/agent/availability exists as a separate
 * endpoint from the item's own `is_available` flag: an item can be perfectly in
 * stock and still not orderable at 9pm because it lives in a lunch-only
 * category.
 *
 * @property int $id
 * @property int $restaurant_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_active
 * @property string|null $available_from Local wall-clock "HH:MM:SS"
 * @property string|null $available_until Local wall-clock "HH:MM:SS"
 * @property list<int>|null $available_days Weekday numbers, 0 = Sunday
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Restaurant $restaurant
 * @property-read Collection<int, MenuItem> $menuItems
 *
 * @method static MenuCategoryFactory factory($count = null, $state = [])
 */
class MenuCategory extends Model
{
    /** @use HasFactory<MenuCategoryFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'available_days' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return HasMany<MenuItem, $this> */
    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('sort_order');
    }

    /**
     * Is this category servable at the given local time?
     *
     * `$at` must already be in the restaurant's timezone — callers get that
     * from Restaurant::now(). Passing a UTC instant here would silently answer
     * the wrong question for most of the day.
     */
    public function isAvailableAt(CarbonInterface $at): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if (is_array($this->available_days) && $this->available_days !== []
            && ! in_array($at->dayOfWeek, $this->available_days, true)) {
            return false;
        }

        if ($this->available_from === null || $this->available_until === null) {
            return true;
        }

        $time = $at->format('H:i:s');

        // A window that wraps past midnight ("22:00"–"02:00") is satisfied by
        // being after the start OR before the end, rather than both.
        if ($this->available_until < $this->available_from) {
            return $time >= $this->available_from || $time <= $this->available_until;
        }

        return $time >= $this->available_from && $time <= $this->available_until;
    }

    public function hasAvailabilityWindow(): bool
    {
        return $this->available_from !== null && $this->available_until !== null;
    }

    /**
     * How the window reads out loud, for the agent to explain a refusal:
     * "the lunch menu is served midday to 3pm".
     */
    public function spokenAvailability(): ?string
    {
        if (! $this->hasAvailabilityWindow()) {
            return null;
        }

        return SpokenTime::range((string) $this->available_from, (string) $this->available_until);
    }
}
