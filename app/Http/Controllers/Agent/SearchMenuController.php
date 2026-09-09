<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Enums\AgentErrorCode;
use App\Http\Requests\Agent\MenuSearchRequest;
use App\Http\Responses\AgentResponse;
use App\Services\Menu\MenuMatchingService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/agent/menu/search
 *
 * The tool the agent reaches for constantly: a caller said something, is it on
 * the menu, and which thing is it?
 *
 * Ambiguity is reported rather than resolved. When a caller says "a burger" and
 * the restaurant sells three, the right answer is not to pick the most popular
 * one — it is to hand all three back and let the agent ask. Guessing here is
 * how an order for the wrong food gets made without anybody noticing until it
 * arrives.
 */
final class SearchMenuController
{
    public function __construct(private MenuMatchingService $menu) {}

    public function __invoke(MenuSearchRequest $request): JsonResponse
    {
        $restaurant = $request->restaurant();
        $validated = $request->validated();

        $result = $this->menu->search(
            $restaurant,
            (string) $validated['query'],
            limit: isset($validated['limit']) ? (int) $validated['limit'] : null,
        );

        if ($result->isEmpty()) {
            return AgentResponse::fail(
                AgentErrorCode::ItemNotFound,
                "I'm sorry, I don't think we do that. Would you like me to run through the menu?",
                $result->toAgentArray(),
            );
        }

        return AgentResponse::ok($result->toAgentArray());
    }
}
