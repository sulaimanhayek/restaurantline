<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Requests\Agent\HoursRequest;
use App\Http\Responses\AgentResponse;
use App\Services\Hours\OpeningHoursService;
use App\Support\SpokenTime;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/agent/hours
 *
 * "What time do you close?" — asked on a large share of calls, and the reason
 * this is a tool rather than a line baked into the system prompt is that a
 * prompt goes stale the moment the restaurant changes its hours, and nobody
 * remembers to re-provision the agent when it does.
 *
 * Times come back both structured and spoken (#0016). A model asked to read
 * `22:30` aloud says "twenty two thirty" often enough to matter.
 */
final class HoursController
{
    public function __construct(private OpeningHoursService $hours) {}

    public function __invoke(HoursRequest $request): JsonResponse
    {
        $restaurant = $request->restaurant();
        $now = $restaurant->now();

        $current = $this->hours->currentWindow($restaurant, $now);
        $next = $this->hours->nextOpening($restaurant, $now);

        return AgentResponse::ok([
            'timezone' => $restaurant->timezone,
            'local_time' => $now->format('H:i'),
            'local_time_spoken' => SpokenTime::of($now->format('H:i')),
            'open_now' => $current !== null,
            'accepting_orders' => $restaurant->is_accepting_orders,
            'closes_at' => $current?->closesAt->format('H:i'),
            'closes_at_spoken' => $current === null
                ? null
                : SpokenTime::of($current->closesAt->format('H:i')),
            'next_opens_at' => $next?->opensAt->toIso8601String(),
            'next_opens_spoken' => $this->hours->spokenNextOpening($restaurant, $now),
            'week' => $this->hours->weeklySummary($restaurant),
        ]);
    }
}
