<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModifierKind;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\OrderItemModifierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A modifier as it was applied to an order line.
 *
 * Duplicates what OrderItem::modifiers_snapshot already holds, on purpose: the
 * snapshot renders a ticket without a join, and this table answers questions
 * across orders — how often callers ask for no onions, which add-on actually
 * earns money, whether one swap is disproportionately involved in complaints.
 *
 * @property int $id
 * @property int $order_item_id
 * @property int|null $modifier_id Reference only
 * @property int|null $modifier_group_id Reference only
 * @property string|null $group_name
 * @property string $name
 * @property ModifierKind $kind
 * @property int $price_delta Signed minor units, after override resolution
 * @property int $quantity
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read OrderItem $orderItem
 * @property-read Modifier|null $modifier
 *
 * @method static OrderItemModifierFactory factory($count = null, $state = [])
 */
class OrderItemModifier extends Model
{
    /** @use HasFactory<OrderItemModifierFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ModifierKind::class,
            'price_delta' => 'integer',
            'quantity' => 'integer',
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<Modifier, $this> */
    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }

    public function money(): Money
    {
        return Money::of($this->price_delta, $this->orderItem->order->restaurant->currency);
    }

    /**
     * "NO ONIONS", "+ Extra cheese", "→ Sweet potato fries".
     */
    public function ticketLabel(): string
    {
        return $this->kind->ticketPrefix().$this->name;
    }
}
