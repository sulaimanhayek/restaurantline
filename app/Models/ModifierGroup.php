<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModifierGroupSelectionType;
use Carbon\CarbonImmutable;
use Database\Factories\ModifierGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A reusable set of choices attachable to many items.
 *
 * The values here are defaults. An item may tighten or relax min/max/required
 * through the attaching pivot — read the effective values with
 * MenuItem::resolvedSelectionRules().
 *
 * @property int $id
 * @property int $restaurant_id
 * @property string $name
 * @property string $slug
 * @property string|null $prompt What the agent says when asking
 * @property ModifierGroupSelectionType $selection_type
 * @property int $min_selections
 * @property int|null $max_selections Null means unlimited
 * @property bool $is_required
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Restaurant $restaurant
 * @property-read Collection<int, Modifier> $modifiers
 * @property-read Collection<int, MenuItem> $menuItems
 *
 * @method static ModifierGroupFactory factory($count = null, $state = [])
 */
class ModifierGroup extends Model
{
    /** @use HasFactory<ModifierGroupFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selection_type' => ModifierGroupSelectionType::class,
            'min_selections' => 'integer',
            'max_selections' => 'integer',
            'is_required' => 'boolean',
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return HasMany<Modifier, $this> */
    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class)->orderBy('sort_order');
    }

    /** @return BelongsToMany<MenuItem, $this> */
    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'menu_item_modifier_group')
            ->withPivot([
                'sort_order',
                'min_selections_override',
                'max_selections_override',
                'is_required_override',
            ])
            ->withTimestamps();
    }

    /**
     * The question the agent asks for this group.
     *
     * Falls back to something serviceable rather than nothing, because a group
     * with no prompt would otherwise leave the agent to improvise — and it
     * improvises badly under time pressure on a phone call.
     */
    public function spokenPrompt(): string
    {
        if ($this->prompt !== null && $this->prompt !== '') {
            return $this->prompt;
        }

        return $this->selection_type === ModifierGroupSelectionType::Single
            ? sprintf('Which %s would you like?', mb_strtolower($this->name))
            : sprintf('Would you like anything from %s?', mb_strtolower($this->name));
    }

    public function allowsMultiple(): bool
    {
        return $this->selection_type === ModifierGroupSelectionType::Multi;
    }
}
