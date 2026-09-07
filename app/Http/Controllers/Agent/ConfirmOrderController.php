<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Enums\AgentErrorCode;
use App\Enums\ConversationOutcome;
use App\Enums\OrderStatus;
use App\Http\Requests\Agent\ConfirmOrderRequest;
use App\Http\Responses\AgentResponse;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/agent/orders/{order}/confirm
 *
 * The caller said yes. This is the moment the order becomes real: the kitchen
 * display picks it up, and the confirmation and payment link go out.
 *
 * Separating this from creation is what makes "always read the order back
 * before creating it" enforceable rather than aspirational. The order was
 * created in `confirming` and priced; the agent read the total aloud; only a
 * yes gets it here.
 *
 * The order is addressed by its `order_number` — the same reference the caller
 * was just given and the same one on the kitchen ticket. Route-model binding on
 * an auto-increment id would leave the agent holding a number nobody in the
 * conversation ever said, and would let a hallucinated integer confirm somebody
 * else's order.
 */
final class ConfirmOrderController
{
    public function __invoke(ConfirmOrderRequest $request, string $order): JsonResponse
    {
        $restaurant = $request->restaurant();

        $found = $restaurant->orders()
            ->with('items.modifiers')
            ->where('order_number', $order)
            ->first();

        if ($found === null) {
            return AgentResponse::fail(
                AgentErrorCode::OrderNotFound,
                "I can't find that order, sorry. Let me start it again.",
                ['order_number' => $order],
            );
        }

        // A retried confirm is a success, not a conflict. ElevenLabs retries a
        // tool call it did not hear back from, and the caller must not be told
        // something went wrong with an order that is perfectly fine.
        if ($found->status === OrderStatus::Confirmed) {
            return AgentResponse::ok(
                $this->payload($found) + ['idempotent_replay' => true],
                $this->say($found),
            );
        }

        if ($found->status !== OrderStatus::Confirming) {
            return AgentResponse::fail(
                AgentErrorCode::OrderNotConfirmable,
                $found->status->isTerminal()
                    ? "That order has already been closed off, I'm afraid."
                    : 'That order is already with the kitchen.',
                ['order_number' => $found->order_number, 'status' => $found->status->value],
            );
        }

        $found->update([
            'status' => OrderStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $found->conversation?->update(['outcome' => ConversationOutcome::OrderPlaced]);

        return AgentResponse::ok($this->payload($found), $this->say($found));
    }

    private function say(Order $order): string
    {
        return sprintf(
            "Lovely, that's confirmed. Your order number is %s and it'll be ready %s.",
            $order->spokenOrderNumber(),
            $order->spokenWait(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'order_number_spoken' => $order->spokenOrderNumber(),
            'status' => $order->status->value,
            'total' => $order->total,
            'total_spoken' => $order->totalMoney()->spoken(),
            'estimated_minutes' => $order->estimated_minutes,
            'estimated_ready_at' => $order->estimated_ready_at?->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
        ];
    }
}
