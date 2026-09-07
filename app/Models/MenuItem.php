<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * A single orderable dish.
 *
 * @property int $id
 * @property int $restaurant_id
 * @property int $menu_category_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $price Minor units
 * @property string|null $sku
 * @property bool $is_available
 * @property list<string>|null $spoken_aliases
 * @property int $sort_order
 * @property int|null $prep_minutes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Restaurant $restaurant
 * @property-read MenuCategory $category
 * @property-read Collection<int, ModifierGroup> $modifierGroups
 * @property-read Collection<int, Modifier> $modifierOverrides
 *
 * @method static MenuItemFactory factory($count = null, $state = [])
 */
class MenuItem extends Model
{
    /** @use HasFactory<MenuItemFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'is_available' => 'boolean',
            'spoken_aliases' => 'array',
            'sort_order' => 'integer',
            'prep_minutes' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return BelongsTo<MenuCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    /**
     * The modifier groups this item offers.
     *
     * The pivot carries the per-item overrides; read them through
     * resolvedSelectionRules() rather than touching the pivot directly, so the
     * `override ?? group` rule lives in exactly one place.
     *
     * @return BelongsToMany<ModifierGroup, $this>
     */
    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'menu_item_modifier_group')
            ->withPivot([
                'sort_order',
                'min_selections_override',
                'max_selections_override',
                'is_required_override',
            ])
            ->withTimestamps()
            ->orderBy('menu_item_modifier_group.sort_order');
    }

    /**
     * Sparse pivot holding price and availability exceptions for individual
     * modifiers on this item. Most items have no rows here at all.
     *
     * @return BelongsToMany<Modifier, $this>
     */
    public function modifierOverrides(): BelongsToMany
    {
        return $this->belongsToMany(Modifier::class, 'menu_item_modifier')
            ->withPivot(['price_delta_override', 'is_available_override'])
            ->withTimestamps();
    }

    // -----------------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------------

    /**
     * @param  Builder<MenuItem>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_available', true);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    public function money(): Money
    {
        return Money::of($this->price, $this->restaurant->currency);
    }

    /**
     * Every string a caller might use for this item, lowercased.
     *
     * The item's own name is always included, so a menu with no aliases at all
     * still matches on exact names.
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

    /**
     * Orderable right now?
     *
     * Two independent gates: the operator's out-of-stock switch, and the
     * category's serving window. Both have to pass, and the agent needs to be
     * able to tell the caller which one failed — "we've run out" and "that's
     * lunch only" are very different sentences.
     */
    public function isOrderableAt(CarbonInterface $at): bool
    {
        return $this->is_available && $this->category->isAvailableAt($at);
    }

    /**
     * Resolve this item's rules for one of its modifier groups.
     *
     * The pivot's override columns win where they are set; otherwise the
     * group's own values apply. This is the single implementation of that rule.
     *
     * @return array{min: int, max: int|null, required: bool}
     */
    public function resolvedSelectionRules(ModifierGroup $group): array
    {
        // Accept a group that arrived through the relation (pivot already
        // attached) or a bare one looked up elsewhere. Silently returning the
        // group defaults for the second case would misprice an item in a way
        // nothing would catch until a customer complained.
        $pivot = $group->getAttribute('pivot')
            ?? $this->modifierGroups->firstWhere('id', $group->id)?->getAttribute('pivot');

        $min = $pivot?->getAttribute('min_selections_override');
        $max = $pivot?->getAttribute('max_selections_override');
        $required = $pivot?->getAttribute('is_required_override');

        return [
            'min' => $min !== null ? (int) $min : $group->min_selections,
            'max' => $max !== null ? (int) $max : $group->max_selections,
            'required' => $required !== null ? (bool) $required : $group->is_required,
        ];
    }

    /**
     * The price delta to charge for a modifier on this item, in minor units.
     *
     * Honours the sparse per-item override before falling back to the
     * modifier's own delta.
     */
    public function resolvedPriceDelta(Modifier $modifier): int
    {
        $override = $this->modifierOverrides
            ->firstWhere('id', $modifier->id)
            ?->getAttribute('pivot')
            ?->getAttribute('price_delta_override');

        return $override !== null ? (int) $override : $modifier->price_delta;
    }

    public function prepMinutes(): int
    {
        return $this->prep_minutes ?? $this->restaurant->collection_prep_minutes;
    }
}
