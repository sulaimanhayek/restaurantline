<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\FulfilmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Observers\OrderObserver;
use App\Support\Money;
use App\Support\SpokenTime;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
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
 * @property PaymentMethod|null $payment_method
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
 * @property CarbonImmutable|null $payment_link_expires_at
 * @property CarbonImmutable|null $paid_at
 * @property int|null $amount_paid Minor units
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
 * @property-read Collection<int, SmsMessage> $smsMessages
 *
 * @method static OrderFactory factory($count = null, $state = [])
 */
#[ObservedBy(OrderObserver::class)]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * Past this many minutes, a wait is read out as a clock time rather than
     * as a duration. Two hours is comfortably longer than any real prep time
     * and short enough that nothing absurd gets said.
     */
    private const SPOKEN_WAIT_CEILING_MINUTES = 120;

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
            'payment_method' => PaymentMethod::class,
            'source' => OrderSource::class,
            'subtotal' => 'integer',
            'delivery_fee' => 'integer',
            'total' => 'integer',
            'estimated_minutes' => 'integer',
            'amount_paid' => 'integer',
            'requested_at' => UtcDateTime::class,
            'payment_link_expires_at' => UtcDateTime::class,
            'paid_at' => UtcDateTime::class,
            'estimated_ready_at' => UtcDateTime::class,
            'confirmed_at' => UtcDateTime::class,
            'accepted_at' => UtcDateTime::class,
            'ready_at' => UtcDateTime::class,
            'completed_at' => UtcDateTime::class,
            'cancelled_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
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

    /** @return HasMany<SmsMessage, $this> */
    public function smsMessages(): HasMany
    {
        return $this->hasMany(SmsMessage::class)->latest('id');
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

    /**
     * "free", not "0 pounds". See PricedOrder::spokenDeliveryFee().
     */
    public function spokenDeliveryFee(): string
    {
        return $this->delivery_fee === 0 ? 'free' : $this->deliveryFeeMoney()->spoken();
    }

    /**
     * How long the caller is waiting, said the way they need to hear it.
     *
     * A count of minutes is right for a normal order and wrong past a couple
     * of hours: nobody hears "about a hundred and eighty minutes" as three
     * o'clock, and an order taken while the kitchen is shut produces numbers
     * like 1026. Beyond the ceiling the clock time is what a person would
     * say, with the day attached once it is no longer today.
     *
     * The preposition travels with the phrase, because "in about 20 minutes"
     * and "tomorrow, around midday" do not take the same one.
     */
    public function spokenWait(): string
    {
        $readyAt = $this->estimated_ready_at;

        if ($readyAt === null) {
            return 'shortly';
        }

        $now = $this->restaurant->now();
        $readyAt = $readyAt->copy()->setTimezone($this->restaurant->timezone);
        $minutes = (int) $this->estimated_minutes;

        if ($minutes <= self::SPOKEN_WAIT_CEILING_MINUTES) {
            return sprintf('in about %d minutes', max(1, $minutes));
        }

        $time = SpokenTime::of($readyAt->format('H:i'));

        return match (true) {
            $readyAt->isSameDay($now) => sprintf('later today, around %s', $time),
            $readyAt->isSameDay($now->addDay()) => sprintf('tomorrow, around %s', $time),
            default => sprintf('on %s, around %s', $readyAt->format('l'), $time),
        };
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
