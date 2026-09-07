<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One line on an order — a snapshot, not a pointer.
 *
 * The name, price and modifiers here were copied at the moment of ordering and
 * are never re-read from the live menu. A price rise next week must not rewrite
 * what a customer was quoted today.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $menu_item_id Reference only; never the source of price
 * @property string $name
 * @property string|null $description
 * @property string|null $sku
 * @property int $unit_price Minor units, before modifiers
 * @property int $quantity
 * @property int $line_total Minor units
 * @property array<int, mixed>|null $modifiers_snapshot
 * @property string|null $notes
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Order $order
 * @property-read MenuItem|null $menuItem
 * @property-read Collection<int, OrderItemModifier> $modifiers
 *
 * @method static OrderItemFactory factory($count = null, $state = [])
 */
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'quantity' => 'integer',
            'line_total' => 'integer',
            'modifiers_snapshot' => 'array',
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<MenuItem, $this> */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /** @return HasMany<OrderItemModifier, $this> */
    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class)->orderBy('sort_order');
    }

    public function unitPriceMoney(): Money
    {
        return Money::of($this->unit_price, $this->order->restaurant->currency);
    }

    public function lineTotalMoney(): Money
    {
        return Money::of($this->line_total, $this->order->restaurant->currency);
    }

    /**
     * How this line reads on a kitchen ticket, modifiers included.
     */
    public function ticketLines(): string
    {
        $lines = [sprintf('%d× %s', $this->quantity, $this->name)];

        foreach ($this->modifiers as $modifier) {
            $lines[] = '   '.$modifier->ticketLabel();
        }

        if ($this->notes !== null && trim($this->notes) !== '') {
            $lines[] = '   ** '.$this->notes;
        }

        return implode("\n", $lines);
    }
}
