<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FulfilmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * An order.
 *
 * Note the two conversation columns. `elevenlabs_conversation_id` is a string
 * carried by every agent tool call and is what makes order creation idempotent:
 * a retried call finds the existing order instead of creating a second one.
 * `conversation_id` is the ordinary foreign key, populated later, when the
 * post-call webhook creates the Conversation row.
 *
 * @property int $id
 * @property int $restaurant_id
 * @property int|null $customer_id
 * @property int|null $address_id
 * @property string $order_number
 * @property FulfilmentType $fulfilment_type
 * @property OrderStatus $status
 * @property PaymentStatus $payment_status
 * @property OrderSource $source
 * @property int $subtotal Minor units
 * @property int $delivery_fee Minor units
 * @property int $total Minor units
 * @property CarbonImmutable|null $requested_at
 * @property CarbonImmutable|null $estimated_ready_at
 * @property int|null $estimated_minutes
 * @property string|null $notes
 * @property string|null $elevenlabs_conversation_id
 * @property int|null $conversation_id
 * @property string|null $payment_link_url
 * @property string|null $payment_reference
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Restaurant $restaurant
 * @property-read Customer|null $customer
 * @property-read Address|null $address
 * @property-read Conversation|null $conversation
 * @property-read Collection<int, OrderItem> $items
 *
 * @method static OrderFactory factory($count = null, $state = [])
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fulfilment_type' => FulfilmentType::class,
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'source' => OrderSource::class,
            'subtotal' => 'integer',
            'delivery_fee' => 'integer',
            'total' => 'integer',
            'estimated_minutes' => 'integer',
            'requested_at' => 'immutable_datetime',
            'estimated_ready_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Address, $this> */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('sort_order');
    }

    // -----------------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------------

    /**
     * Orders the kitchen should be looking at right now.
     *
     * Excludes `confirming` deliberately: the caller has not agreed to those
     * yet, and a chef who starts cooking an unconfirmed order has been given
     * bad information by the software.
     *
     * @param  Builder<Order>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', array_map(
            static fn (OrderStatus $status): string => $status->value,
            OrderStatus::liveOnKitchenDisplay(),
        ));
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function scopeCommitted(Builder $query): void
    {
        $query->whereNotIn('status', [OrderStatus::Draft->value, OrderStatus::Confirming->value]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    public function subtotalMoney(): Money
    {
        return Money::of($this->subtotal, $this->restaurant->currency);
    }

    public function deliveryFeeMoney(): Money
    {
        return Money::of($this->delivery_fee, $this->restaurant->currency);
    }

    public function totalMoney(): Money
    {
        return Money::of($this->total, $this->restaurant->currency);
    }

    public function isDelivery(): bool
    {
        return $this->fulfilment_type === FulfilmentType::Delivery;
    }

    /**
     * Minutes since the kitchen took this on, used by the colour-coded timer on
     * the kitchen display. Measured from confirmation rather than creation,
     * because the time a caller spent choosing is not the kitchen's problem.
     */
    public function minutesSinceConfirmed(): int
    {
        $from = $this->confirmed_at ?? $this->created_at;

        return (int) $from->diffInMinutes(CarbonImmutable::now());
    }

    /**
     * The order number, spaced out for the agent to read aloud.
     *
     * "A-4721" read literally becomes "a dash four thousand seven hundred and
     * twenty one", which no caller can write down. Spacing the digits forces
     * them to be read individually.
     */
    public function spokenOrderNumber(): string
    {
        return trim(implode(' ', str_split(str_replace('-', ' ', $this->order_number))));
    }

    /**
     * A one-line summary for the kitchen display and the dashboard.
     */
    public function summaryLine(): string
    {
        return $this->items
            ->map(static fn (OrderItem $item): string => sprintf('%d× %s', $item->quantity, $item->name))
            ->implode(', ');
    }
}
