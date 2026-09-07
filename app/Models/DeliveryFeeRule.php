<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DeliveryFeeRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One distance band in the delivery fee ladder.
 *
 * Evaluated by PricingService in ascending sort order; the first band whose
 * `up_to_metres` covers the distance wins. A null `up_to_metres` is the
 * catch-all and belongs last.
 *
 * @property int $id
 * @property int $restaurant_id
 * @property int|null $up_to_metres Null means "and beyond"
 * @property int $fee Minor units
 * @property int|null $free_over_subtotal Minor units; null means the fee always applies
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Restaurant $restaurant
 *
 * @method static DeliveryFeeRuleFactory factory($count = null, $state = [])
 */
class DeliveryFeeRule extends Model
{
    /** @use HasFactory<DeliveryFeeRuleFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'up_to_metres' => 'integer',
            'fee' => 'integer',
            'free_over_subtotal' => 'integer',
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

    public function covers(int $distanceMetres): bool
    {
        return $this->up_to_metres === null || $distanceMetres <= $this->up_to_metres;
    }
}
