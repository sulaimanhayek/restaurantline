<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Enums\ConversationOutcome;
use App\Http\Requests\Agent\EscalateRequest;
use App\Http\Responses\AgentResponse;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/agent/escalate
 *
 * Hand the caller to a person.
 *
 * This endpoint exists because of a constraint the README states outright:
 * always offer a human. A voice agent with no way out traps people — the caller
 * with a severe allergy, the caller whose order arrived wrong last week, the
 * caller the speech engine simply cannot understand. Every one of them deserves
 * a person, and an agent that cannot fetch one will instead keep asking them to
 * repeat themselves until they hang up.
 *
 * It always succeeds. Even with no transfer number configured it returns 200
 * with something to say, because the one thing that must never happen here is
 * the agent's escape hatch failing and the caller being stuck in the loop the
 * hatch existed to break.
 *
 * The conversation is flagged for review on the way out. Escalations are the
 * highest-signal recordings in the system — each one is a case the agent could
 * not handle, which is exactly what the next round of prompt work needs.
 */
final class EscalateController
{
    public function __invoke(EscalateRequest $request): JsonResponse
    {
        $restaurant = $request->restaurant();
        $reason = $request->reason();
        $number = $restaurant->transfer_phone_number;

        $conversation = Conversation::query()->firstOrCreate(
            ['elevenlabs_conversation_id' => (string) $request->conversationId()],
            [
                'restaurant_id' => $restaurant->id,
                'elevenlabs_agent_id' => $restaurant->elevenlabs_agent_id,
                'started_at' => now(),
            ],
        );

        $conversation->update(['outcome' => ConversationOutcome::Escalated]);
        $conversation->flagForReview($reason);

        Log::info('Agent escalated a call to a human.', [
            'conversation' => $conversation->elevenlabs_conversation_id,
            'reason' => $reason,
            'transfer_configured' => $number !== null,
        ]);

        if ($number === null) {
            // Worth being loud about: an installation with no transfer number
            // has an escalation tool that cannot escalate, and the only place
            // that shows up otherwise is a caller's bad afternoon.
            Log::warning('Escalation requested but no transfer_phone_number is set on the restaurant.');

            return AgentResponse::ok(
                [
                    'transfer_available' => false,
                    'reason' => $reason,
                    'conversation_flagged' => true,
                ],
                "I'm sorry, I can't put you through to anyone right now. If you'd like to call back and ask for the manager, someone will be able to help.",
            );
        }

        return AgentResponse::ok(
            [
                'transfer_available' => true,
                'transfer_phone_number' => $number,
                'reason' => $reason,
                'conversation_flagged' => true,
            ],
            'Of course — let me put you through to someone who can help. Bear with me one moment.',
        );
    }
}
