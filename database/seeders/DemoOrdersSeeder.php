<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ConversationOutcome;
use App\Enums\FulfilmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A day's worth of plausible traffic, so the dashboard and the kitchen display
 * have something to render on first boot.
 *
 * Two of the five calls did not produce an order. That ratio is deliberate:
 * the conversation review screen is only worth building if there is something
 * to review, and a demo where every call succeeds teaches a forker nothing
 * about the part of the system they will actually spend their time in.
 */
class DemoOrdersSeeder extends Seeder
{
    public function run(): void
    {
        $restaurant = Restaurant::query()->where('slug', 'ember-grill')->firstOrFail();

        $this->liveCollectionOrder($restaurant);
        $this->liveDeliveryOrder($restaurant);
        $this->completedOrder($restaurant);
        $this->abandonedCall($restaurant);
        $this->outOfAreaCall($restaurant);
    }

    /**
     * On the grill right now: a half chicken, hot, with an add-on.
     */
    private function liveCollectionOrder(Restaurant $restaurant): void
    {
        $customer = $this->customer($restaurant, '+447700900001', 'Priya Raman', orders: 3);
        $conversation = $this->conversation($restaurant, $customer->phone_number, ConversationOutcome::OrderPlaced, [
            ['role' => 'agent', 'message' => 'Ember Grill, is it delivery or collection?'],
            ['role' => 'user', 'message' => 'Collection please.'],
            ['role' => 'user', 'message' => 'Half a chicken, hot, and some wings.'],
            ['role' => 'agent', 'message' => 'That is a half chicken, hot, and five wings. Seventeen pounds. Shall I put that in?'],
            ['role' => 'user', 'message' => 'Yes please.'],
        ]);

        $chicken = $this->item($restaurant, 'flame-grilled-chicken');
        $wings = $this->item($restaurant, 'flame-grilled-wings');

        $half = $this->modifier($restaurant, 'half-chicken');
        $hot = $this->modifier($restaurant, 'hot');
        $chips = $this->modifier($restaurant, 'chips');

        $order = $this->order($restaurant, $customer, $conversation, [
            'fulfilment_type' => FulfilmentType::Collection,
            'status' => OrderStatus::Preparing,
            'payment_status' => PaymentStatus::CashOnCollection,
            'estimated_minutes' => 20,
            'confirmed_at' => now()->subMinutes(9),
            'accepted_at' => now()->subMinutes(8),
        ]);

        $this->line($order, $chicken, 1, [$half, $hot, $chips]);
        $this->line($order, $wings, 1, [$this->modifier($restaurant, 'medium')]);

        $this->recalculate($order);
    }

    /**
     * Waiting to be accepted: a delivery with a removal on it, which is the
     * case a kitchen ticket most often gets wrong.
     */
    private function liveDeliveryOrder(Restaurant $restaurant): void
    {
        $customer = $this->customer($restaurant, '+447700900002', 'Tom Whitaker', orders: 1);

        $address = Address::query()->updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id, 'line_1' => '42 Cheshire Street'],
            [
                'raw_spoken_text' => 'forty two cheshire street, flat b, e2',
                'formatted_address' => '42 Cheshire Street, London E2 6EH, UK',
                'line_2' => 'Flat B',
                'city' => 'London',
                'postcode' => 'E2 6EH',
                'country' => 'GB',
                'latitude' => 51.5241,
                'longitude' => -0.0698,
                'geocode_confidence' => 0.940,
                'geocode_provider' => 'fake',
                'verified_at' => now()->subMinutes(4),
                'distance_metres' => 420,
                'is_default' => true,
            ],
        );

        $conversation = $this->conversation($restaurant, $customer->phone_number, ConversationOutcome::OrderPlaced, [
            ['role' => 'agent', 'message' => 'Ember Grill, is it delivery or collection?'],
            ['role' => 'user', 'message' => 'Delivery. Forty two Cheshire Street, flat B.'],
            ['role' => 'agent', 'message' => 'Forty two Cheshire Street, flat B, E2 6EH. Is that right?'],
            ['role' => 'user', 'message' => 'That is it. Double beef burger, no onions, and chips.'],
        ]);

        $burger = $this->item($restaurant, 'double-beef-burger');
        $chips = $this->item($restaurant, 'chips');

        $order = $this->order($restaurant, $customer, $conversation, [
            'fulfilment_type' => FulfilmentType::Delivery,
            'address_id' => $address->id,
            'status' => OrderStatus::Confirmed,
            'payment_status' => PaymentStatus::LinkSent,
            'estimated_minutes' => 45,
            'confirmed_at' => now()->subMinutes(2),
            'delivery_fee' => 199,
            'notes' => 'Buzzer is broken — ring the mobile.',
        ]);

        $this->line($order, $burger, 1, [
            $this->modifier($restaurant, 'brioche-bun'),
            $this->modifier($restaurant, 'onions'),
            $this->modifier($restaurant, 'chips'),
        ]);
        $this->line($order, $chips, 1, [$this->modifier($restaurant, 'large')]);

        $this->recalculate($order);
    }

    /**
     * Yesterday's, so the orders list is not entirely live rows.
     */
    private function completedOrder(Restaurant $restaurant): void
    {
        $customer = $this->customer($restaurant, '+447700900003', 'Dawn Okafor', orders: 12);
        $conversation = $this->conversation($restaurant, $customer->phone_number, ConversationOutcome::OrderPlaced, [
            ['role' => 'agent', 'message' => 'Ember Grill — hello again. Collection?'],
            ['role' => 'user', 'message' => 'Yes. The usual, veggie burger, no mayo.'],
        ], startedAt: now()->subDay());

        $burger = $this->item($restaurant, 'halloumi-avocado-burger');

        $order = $this->order($restaurant, $customer, $conversation, [
            'fulfilment_type' => FulfilmentType::Collection,
            'status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'estimated_minutes' => 20,
            'confirmed_at' => now()->subDay(),
            'accepted_at' => now()->subDay(),
            'ready_at' => now()->subDay()->addMinutes(18),
            'completed_at' => now()->subDay()->addMinutes(25),
            'created_at' => now()->subDay(),
        ]);

        $this->line($order, $burger, 1, [
            $this->modifier($restaurant, 'lettuce-wrap'),
            $this->modifier($restaurant, 'mayo'),
            $this->modifier($restaurant, 'side-salad'),
        ]);

        $this->recalculate($order);
    }

    /**
     * The caller hung up mid-order. There is no Order row, only the abandoned
     * conversation — which is exactly what you want to be able to read back.
     */
    private function abandonedCall(Restaurant $restaurant): void
    {
        $this->conversation($restaurant, '+447700900004', ConversationOutcome::OrderAbandoned, [
            ['role' => 'agent', 'message' => 'Ember Grill, is it delivery or collection?'],
            ['role' => 'user', 'message' => 'Delivery. Hang on — how long is it?'],
            ['role' => 'agent', 'message' => 'About forty five minutes tonight.'],
            ['role' => 'user', 'message' => 'Right, let me call you back.'],
        ], needsReview: true, reviewReason: 'Caller left over the delivery time quote.');
    }

    private function outOfAreaCall(Restaurant $restaurant): void
    {
        $this->conversation($restaurant, '+447700900005', ConversationOutcome::OutsideDeliveryArea, [
            ['role' => 'agent', 'message' => 'Ember Grill, is it delivery or collection?'],
            ['role' => 'user', 'message' => 'Delivery to Ealing.'],
            ['role' => 'agent', 'message' => 'I am sorry, Ealing is outside our delivery area — we go about three miles from Brick Lane. You are very welcome to collect.'],
            ['role' => 'user', 'message' => 'No worries, thanks.'],
        ]);
    }

    private function customer(Restaurant $restaurant, string $phone, string $name, int $orders): Customer
    {
        return Customer::query()->updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'phone_number' => $phone],
            [
                'name' => $name,
                'order_count' => $orders,
                'first_ordered_at' => $orders > 0 ? now()->subMonths(4) : null,
                'last_ordered_at' => $orders > 0 ? now()->subDays(6) : null,
            ],
        );
    }

    /**
     * @param  list<array{role: string, message: string}>  $transcript
     */
    private function conversation(
        Restaurant $restaurant,
        string $callerNumber,
        ConversationOutcome $outcome,
        array $transcript,
        ?Carbon $startedAt = null,
        bool $needsReview = false,
        ?string $reviewReason = null,
    ): Conversation {
        $startedAt ??= now()->subMinutes(12);

        return Conversation::query()->updateOrCreate(
            ['elevenlabs_conversation_id' => 'conv_demo_'.Str::lower(Str::random(16))],
            [
                'restaurant_id' => $restaurant->id,
                'elevenlabs_agent_id' => $restaurant->elevenlabs_agent_id ?? 'agent_demo_seed',
                'caller_number' => $callerNumber,
                'started_at' => $startedAt,
                'ended_at' => $startedAt->copy()->addSeconds(96),
                'duration_seconds' => 96,
                'transcript' => $transcript,
                'outcome' => $outcome,
                'needs_review' => $needsReview,
                'review_reason' => $reviewReason,
                // Roughly what a 96 second call costs at the time of writing.
                'cost' => 14,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(Restaurant $restaurant, Customer $customer, Conversation $conversation, array $attributes): Order
    {
        $order = new Order;

        $order->forceFill(array_merge([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'order_number' => $this->nextOrderNumber($restaurant),
            'source' => OrderSource::Voice,
            'subtotal' => 0,
            'delivery_fee' => 0,
            'total' => 0,
            'conversation_id' => $conversation->id,
            'elevenlabs_conversation_id' => $conversation->elevenlabs_conversation_id,
            'estimated_ready_at' => now()->addMinutes(20),
        ], $attributes))->save();

        return $order;
    }

    /**
     * Snapshot a menu item and its chosen modifiers onto the order.
     *
     * @param  list<Modifier>  $modifiers
     */
    private function line(Order $order, MenuItem $item, int $quantity, array $modifiers): OrderItem
    {
        $deltas = 0;

        foreach ($modifiers as $modifier) {
            $deltas += $item->resolvedPriceDelta($modifier);
        }

        $unitPrice = $item->price + $deltas;

        $line = OrderItem::query()->create([
            'order_id' => $order->id,
            'menu_item_id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'sku' => $item->sku,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_total' => $unitPrice * $quantity,
            'modifiers_snapshot' => array_map(
                static fn (Modifier $modifier): array => [
                    'name' => $modifier->name,
                    'kind' => $modifier->kind->value,
                    'price_delta' => $item->resolvedPriceDelta($modifier),
                ],
                $modifiers,
            ),
            'sort_order' => $order->items()->count(),
        ]);

        foreach ($modifiers as $index => $modifier) {
            OrderItemModifier::query()->create([
                'order_item_id' => $line->id,
                'modifier_id' => $modifier->id,
                'modifier_group_id' => $modifier->modifier_group_id,
                'group_name' => $modifier->group->name,
                'name' => $modifier->name,
                'kind' => $modifier->kind,
                'price_delta' => $item->resolvedPriceDelta($modifier),
                'quantity' => 1,
                'sort_order' => $index,
            ]);
        }

        return $line;
    }

    private function recalculate(Order $order): void
    {
        $subtotal = (int) $order->items()->sum('line_total');

        $order->forceFill([
            'subtotal' => $subtotal,
            'total' => $subtotal + $order->delivery_fee,
        ])->save();
    }

    private function nextOrderNumber(Restaurant $restaurant): string
    {
        $count = Order::query()->where('restaurant_id', $restaurant->id)->count();

        // No zero padding: the agent reads this back digit by digit, and
        // "zero one zero one" is a worse thing to hear than "one oh one".
        return (string) ($count + 101);
    }

    private function item(Restaurant $restaurant, string $slug): MenuItem
    {
        return MenuItem::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    private function modifier(Restaurant $restaurant, string $slug): Modifier
    {
        return Modifier::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('slug', $slug)
            ->firstOrFail();
    }
}
