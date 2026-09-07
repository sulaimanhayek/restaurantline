<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Enums\AgentErrorCode;
use Illuminate\Http\JsonResponse;

/**
 * The single shape every agent tool endpoint replies in.
 *
 * Two rules, both of which exist because the consumer is a language model in
 * the middle of a phone call rather than a program:
 *
 * 1. A domain failure is a 200 with `ok: false` (#0017). "We don't sell sushi"
 *    is not an error condition, it is a thing the agent has to say out loud,
 *    and it must reach the model as content rather than as a failed tool call.
 *    Real 4xx is reserved for a bad token, a malformed body, and rate limiting.
 *
 * 2. Every failure carries a `say` (#0016). A code alone leaves the model to
 *    invent a sentence, and the sentence it invents when a tool fails is
 *    usually an apology for a technical problem the caller cannot help with.
 *
 * @see docs/DECISIONS.md #0016, #0017
 */
final class AgentResponse
{
    /**
     * @param  array<string, mixed>  $data
     * @param  string|null  $say  Only for the three endpoints whose whole job is
     *                            an utterance — the quote read-back, the order
     *                            confirmation, and the escalation hand-off.
     *                            Everywhere else the model composes its own line
     *                            from the data, so restyling the agent's voice
     *                            means editing the prompt template rather than a
     *                            controller.
     */
    public static function ok(array $data = [], ?string $say = null): JsonResponse
    {
        return response()->json(
            ['ok' => true] + ($say !== null ? ['say' => $say] : []) + $data,
        );
    }

    /**
     * @param  array<string, mixed>  $data  Anything that helps the agent recover:
     *                                      the alternatives it could offer, the
     *                                      shortfall it should mention, the
     *                                      candidates it should ask between.
     */
    public static function fail(AgentErrorCode $code, string $say, array $data = []): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => [
                'code' => $code->value,
                'say' => $say,
            ],
        ] + $data);
    }
}
