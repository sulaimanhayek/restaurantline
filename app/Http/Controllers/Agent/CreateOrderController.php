<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Enums\AgentErrorCode;
use App\Enums\FulfilmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Http\Requests\Agent\CreateOrderRequest;
use App\Http\Responses\AgentResponse;
use App\Models\Address;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Restaurant;
use App\Services\Agent\AddressToken;
use App\Services\Agent\AddressTokenException;
use App\Services\Agent\CartAssembler;
use App\Services\Agent\CartAssemblyException;
use App\Services\Agent\OrderNumberGenerator;
use App\Services\Hours\OpeningHoursService;
use App\Services\Pricing\PricedLine;
use App\Services\Pricing\PricedOrder;
use App\Services\Pricing\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * POST /api/agent/orders
 *
 * Creates the order — in `confirming`, never in `confirmed`.
 *
 * That two-step is one of this project's load-bearing constraints. An order
 * here is a basket the agent has read back and is waiting on a yes for. The
 * kitchen cannot see it, no confirmation goes out, and a caller who hangs up
 * mid-sentence leaves an abandoned `confirming` row somebody can look at rather
 * than food nobody ordered going onto the pass.
 *
 * Delivery orders are held to the address rule the README states: the address
 * must have been read back aloud and agreed to. It is enforced twice over —
 * the sealed token proves the address came from the geocoder rather than from
 * the model (#0019), and `verified_at` records the agent's assertion that the
 * caller confirmed it. An address row without `verified_at` cannot reach an
 * order, and the guard below says so in as many words.
 *
 * Idempotent on the conversation. ElevenLabs retries a tool call it does not
 * hear back from, and a retry must return the order that already exists rather
 * than cook the food twice.
 */
final class CreateOrderController
{
    public function __construct(
        private CartAssembler $assembler,
        private PricingService $pricing,
        private AddressToken $tokens,
        private OrderNumberGenerator $orderNumbers,
        private OpeningHoursService $hours,
    ) {}

    public function __invoke(CreateOrderRequest $request): JsonResponse
    {
        $restaurant = $request->restaurant();
        $fulfilment = $request->fulfilment();
        $conversationId = (string) $request->conversationId();

        // Idempotency first, before any work. A retried call must be cheap and
        // must not create a second customer, address or order number.
        $existing = $restaurant->orders()
            ->with('items.modifiers')
            ->where('elevenlabs_conversation_id', $conversationId)
            ->first();

        if ($existing !== null) {
            return AgentResponse::ok($this->orderPayload($existing) + ['idempotent_replay' => true]);
        }

        if (! $restaurant->is_accepting_orders) {
            return AgentResponse::fail(
                AgentErrorCode::NotAcceptingOrders,
                "I'm sorry, the kitchen has paused new orders for the moment.",
            );
        }

        // The agent is meant to have called /availability first, and this is
        // the endpoint that must not depend on it having done so. Without this
        // check a call at midnight quietly books an order for tomorrow lunch
        // and reads the wait out as a thousand-odd minutes.
        if (! $this->hours->isOpenAt($restaurant)) {
            $opens = $this->hours->spokenNextOpening($restaurant);

            return AgentResponse::fail(
                AgentErrorCode::Closed,
                $opens === null
                    ? "I'm sorry, we're closed at the moment."
                    : sprintf("I'm sorry, we're closed at the moment. We open again %s.", $opens),
                ['next_opens_spoken' => $opens],
            );
        }

        $addressPayload = null;

        if ($fulfilment === FulfilmentType::Delivery) {
            $outcome = $this->openAddress($request);

            if ($outcome instanceof JsonResponse) {
                return $outcome;
            }

            $addressPayload = $outcome;
        }

        try {
            $cart = $this->assembler->assemble(
                $restaurant,
                $fulfilment,
                $request->items(),
                $addressPayload === null ? null : (int) $addressPayload['distance_metres'],
            );
        } catch (CartAssemblyException $exception) {
            return AgentResponse::fail($exception->errorCode, $exception->say, $exception->data);
        }

        $priced = $this->pricing->price($cart);

        if (! $priced->meetsMinimum) {
            return AgentResponse::fail(
                AgentErrorCode::BelowMinimum,
                sprintf(
                    "That's %s short of our %s minimum. Would you like to add anything?",
                    $priced->shortfallMoney()->spoken(),
                    $restaurant->minimumOrderValue()->spoken(),
                ),
                $priced->toAgentArray(),
            );
        }

        $order = DB::transaction(fn (): Order => $this->persist(
            $restaurant,
            $request,
            $priced,
            $fulfilment,
            $conversationId,
            $addressPayload,
        ));

        return AgentResponse::ok(
            $this->orderPayload($order),
            sprintf(
                "That's all in. Your order number is %s, and it'll be ready %s.",
                $order->spokenOrderNumber(),
                $order->spokenWait(),
            ),
        );
    }

    /**
     * Unseal the address and check it is one we can actually deliver to.
     *
     * Returns the opened payload, or the response to send instead.
     *
     * @return array{iat: int, address: array<string, mixed>, raw_spoken: string, spoken: string, within_delivery_area: bool, distance_metres: int}|JsonResponse
     */
    private function openAddress(CreateOrderRequest $request): array|JsonResponse
    {
        if (! $request->addressConfirmed()) {
            return AgentResponse::fail(
                AgentErrorCode::AddressNotConfirmed,
                'Let me just check the address with you before I put this through.',
            );
        }

        try {
            $payload = $this->tokens->open((string) $request->addressToken());
        } catch (AddressTokenException $exception) {
            return AgentResponse::fail(
                $exception->errorCode,
                "I've lost track of that address, sorry. Could you give it to me again?",
            );
        }

        if (! $payload['within_delivery_area']) {
            return AgentResponse::fail(
                AgentErrorCode::OutsideDeliveryArea,
                "That address is outside the area we deliver to, I'm afraid. You're very welcome to collect.",
                ['distance_metres' => $payload['distance_metres']],
            );
        }

        return $payload;
    }

    /**
     * @param  array{iat: int, address: array<string, mixed>, raw_spoken: string, spoken: string, within_delivery_area: bool, distance_metres: int}|null  $addressPayload
     */
    private function persist(
        Restaurant $restaurant,
        CreateOrderRequest $request,
        PricedOrder $priced,
        FulfilmentType $fulfilment,
        string $conversationId,
        ?array $addressPayload,
    ): Order {
        $customer = Customer::query()->firstOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'phone_number' => $request->customerPhoneNumber(),
            ],
            ['name' => $request->customerName()],
        );

        // A regular who gives their name on the third call should have it
        // recorded, but a call where the agent did not catch it must not wipe
        // the name from the two calls that did.
        if ($customer->name === null && $request->customerName() !== null) {
            $customer->update(['name' => $request->customerName()]);
        }

        $address = null;

        if ($addressPayload !== null) {
            /** @var array<string, mixed> $attributes */
            $attributes = $addressPayload['address'];

            // forceFill rather than create(): the attribute keys come out of
            // our own seal (#0019) and are known good, but they arrive as a
            // dynamic array, which static analysis cannot check against the
            // model's columns. Address is unguarded, so the two are identical
            // at runtime.
            $address = new Address;
            $address->forceFill($attributes + [
                'restaurant_id' => $restaurant->id,
                'customer_id' => $customer->id,
                // The one thing the geocoder cannot give us and the transcript
                // can. Kept forever: when a driver cannot find the house, this
                // is the field that explains what the caller actually said.
                'raw_spoken_text' => $addressPayload['raw_spoken'],
                // Set here and only here. This is the agent asserting that the
                // caller heard the address read back and agreed to it — an
                // assertion, timestamped, traceable to a turn in the
                // transcript. Nothing server-side can verify it, so it is
                // stored as what it is rather than as a fact.
                'verified_at' => now(),
            ]);

            if ($address->verified_at === null) {
                // Unreachable as written, and deliberately still here. The rule
                // this enforces — no unverified address ever reaches an order —
                // is one somebody will eventually try to relax by making
                // `verified_at` conditional, and this is where that attempt
                // stops. Checked before the insert, so the row never exists.
                throw new RuntimeException('Refusing to attach an unverified address to an order.');
            }

            $address->save();
        }

        $conversation = Conversation::query()->firstOrCreate(
            ['elevenlabs_conversation_id' => $conversationId],
            [
                'restaurant_id' => $restaurant->id,
                'elevenlabs_agent_id' => $restaurant->elevenlabs_agent_id,
                'caller_number' => $request->customerPhoneNumber(),
                'started_at' => now(),
            ],
        );

        $prepMinutes = $fulfilment === FulfilmentType::Delivery
            ? $restaurant->delivery_prep_minutes
            : $restaurant->collection_prep_minutes;

        $estimate = $this->hours->readyEstimate($restaurant, $prepMinutes);

        $order = Order::query()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'address_id' => $address?->id,
            'conversation_id' => $conversation->id,
            'elevenlabs_conversation_id' => $conversationId,
            'order_number' => $this->orderNumbers->generate($restaurant),
            'fulfilment_type' => $fulfilment,
            'status' => OrderStatus::Confirming,
            'source' => OrderSource::Voice,
            'subtotal' => $priced->subtotal,
            'delivery_fee' => $priced->deliveryFee,
            'total' => $priced->total,
            'requested_at' => now(),
            'estimated_ready_at' => $estimate['ready_at'],
            'estimated_minutes' => $estimate['minutes'],
            'notes' => $request->validated()['notes'] ?? null,
        ]);

        foreach ($priced->lines as $line) {
            $this->persistLine($order, $line);
        }

        return $order->load('items.modifiers');
    }

    private function persistLine(Order $order, PricedLine $line): void
    {
        // Snapshot, not a reference. The menu will change — prices go up,
        // items get renamed, modifiers get retired — and an order from last
        // Tuesday has to keep saying what was actually bought and charged.
        $item = $order->items()->create([
            'menu_item_id' => $line->menuItemId,
            'name' => $line->name,
            'description' => $line->description,
            'sku' => $line->sku,
            'unit_price' => $line->unitPriceWithModifiers(),
            'quantity' => $line->quantity,
            'line_total' => $line->lineTotal,
            'modifiers_snapshot' => $line->toSnapshot(),
            'notes' => $line->notes,
            'sort_order' => $line->sortOrder,
        ]);

        foreach ($line->modifiers as $index => $modifier) {
            $item->modifiers()->create([
                'modifier_id' => $modifier->modifierId,
                'modifier_group_id' => $modifier->modifierGroupId,
                'group_name' => $modifier->groupName,
                'name' => $modifier->name,
                'kind' => $modifier->kind,
                'price_delta' => $modifier->priceDelta,
                'quantity' => $modifier->quantity,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'order_number_spoken' => $order->spokenOrderNumber(),
            'status' => $order->status->value,
            'fulfilment' => $order->fulfilment_type->value,
            'subtotal' => $order->subtotal,
            'subtotal_spoken' => $order->subtotalMoney()->spoken(),
            'delivery_fee' => $order->delivery_fee,
            'delivery_fee_spoken' => $order->spokenDeliveryFee(),
            'total' => $order->total,
            'total_spoken' => $order->totalMoney()->spoken(),
            'estimated_minutes' => $order->estimated_minutes,
            'estimated_ready_at' => $order->estimated_ready_at?->toIso8601String(),
            'items' => $order->items
                ->map(static fn ($item): array => [
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'modifiers' => $item->modifiers
                        ->map(static fn ($modifier): string => $modifier->name)
                        ->values()
                        ->all(),
                    'line_total' => $item->line_total,
                    'line_total_spoken' => $item->lineTotalMoney()->spoken(),
                ])
                ->values()
                ->all(),
            // Said out loud only once the caller has agreed, by
            // POST /orders/{order}/confirm. Nothing is committed yet.
            'requires_confirmation' => $order->status === OrderStatus::Confirming,
        ];
    }
}
