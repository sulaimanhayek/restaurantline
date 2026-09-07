<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Enums\AgentErrorCode;
use App\Enums\FulfilmentType;
use App\Http\Requests\Agent\AvailabilityRequest;
use App\Http\Responses\AgentResponse;
use App\Services\Hours\OpeningHoursService;
use App\Support\SpokenTime;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/agent/availability
 *
 * "Can I order right now, and when would it be ready?"
 *
 * The agent is expected to call this before taking an order rather than after,
 * because the alternative is a caller who spends four minutes choosing food and
 * is then told the kitchen shut at ten.
 *
 * Two separate things can stop an order, and they are reported separately:
 * the kitchen being closed, and the restaurant having switched off ordering
 * while still open — the button a manager presses when the kitchen is
 * drowning. Conflating them would have the agent tell a caller to try again
 * tomorrow when the real answer is twenty minutes.
 */
final class AvailabilityController
{
    public function __construct(private OpeningHoursService $hours) {}

    public function __invoke(AvailabilityRequest $request): JsonResponse
    {
        $restaurant = $request->restaurant();
        $fulfilment = $request->fulfilment();
        $now = $restaurant->now();

        $open = $this->hours->isOpenAt($restaurant, $now);
        $next = $this->hours->nextOpening($restaurant, $now);
        $opens = $this->hours->spokenNextOpening($restaurant, $now);

        if (! $restaurant->is_accepting_orders) {
            return AgentResponse::fail(
                AgentErrorCode::NotAcceptingOrders,
                "I'm sorry, the kitchen has paused new orders for the moment. Could you try again shortly?",
                ['open_now' => $open, 'accepting_orders' => false],
            );
        }

        if (! $open) {
            return AgentResponse::fail(
                AgentErrorCode::Closed,
                $opens === null
                    ? "I'm sorry, we're closed at the moment."
                    : sprintf("I'm sorry, we're closed at the moment. We open again %s.", $opens),
                [
                    'open_now' => false,
                    'accepting_orders' => true,
                    'next_opens_at' => $next?->opensAt->toIso8601String(),
                    'next_opens_spoken' => $opens,
                ],
            );
        }

        $prepMinutes = $fulfilment === FulfilmentType::Delivery
            ? $restaurant->delivery_prep_minutes
            : $restaurant->collection_prep_minutes;

        $estimate = $this->hours->readyEstimate($restaurant, $prepMinutes, $now);

        return AgentResponse::ok([
            'open_now' => true,
            'accepting_orders' => true,
            'fulfilment' => $fulfilment->value,
            'estimated_minutes' => $estimate['minutes'],
            'estimated_minutes_spoken' => sprintf('about %d minutes', $estimate['minutes']),
            'ready_at' => $estimate['ready_at']->toIso8601String(),
            'ready_at_spoken' => SpokenTime::of($estimate['ready_at']->format('H:i')),
            'closes_at' => $this->hours->currentWindow($restaurant, $now)?->closesAt->format('H:i'),
            'minimum_order_value' => $restaurant->minimum_order_value,
            'minimum_order_value_spoken' => $restaurant->minimumOrderValue()->spoken(),
            'delivery_radius_metres' => $restaurant->delivery_radius_metres,
        ]);
    }
}
