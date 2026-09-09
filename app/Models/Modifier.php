<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\ModifierKind;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\ModifierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One choice within a modifier group.
 *
 * Sizes, add-ons, removals and swaps are all rows in this table, distinguished
 * only by `kind`. That uniformity is the whole point: "No onions" carries its
 * own spoken aliases and flows through the same matcher, the same pricing rule
 * and the same order snapshot as "Large" does.
 *
 * @property int $id
 * @property int $restaurant_id
 * @property int $modifier_group_id
 * @property string $name
 * @property string $slug
 * @property int $price_delta Signed minor units
 * @property ModifierKind $kind
 * @property list<string>|null $spoken_aliases
 * @property bool $is_available
 * @property bool $is_default
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Restaurant $restaurant
 * @property-read ModifierGroup $group
 *
 * @method static ModifierFactory factory($count = null, $state = [])
 */
class Modifier extends Model
{
    /** @use HasFactory<ModifierFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_delta' => 'integer',
            'kind' => ModifierKind::class,
            'spoken_aliases' => 'array',
            'is_available' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
        ];
    }

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return BelongsTo<ModifierGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    public function money(): Money
    {
        return Money::of($this->price_delta, $this->restaurant->currency);
    }

    /**
     * Every string a caller might use for this modifier, lowercased.
     *
     * @return list<string>
     */
    public function matchableTerms(): array
    {
        $terms = array_merge([$this->name], $this->spoken_aliases ?? []);

        return array_values(array_unique(array_map(
            static fn (string $term): string => mb_strtolower(trim($term)),
            $terms,
        )));
    }

    public function isFree(): bool
    {
        return $this->price_delta === 0;
    }

    /**
     * How this reads on a kitchen ticket: "NO ONIONS", "+ Extra cheese".
     */
    public function ticketLabel(): string
    {
        return $this->kind->ticketPrefix().$this->name;
    }
}
