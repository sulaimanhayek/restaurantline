<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Enums\AgentErrorCode;
use App\Enums\FulfilmentType;
use App\Http\Requests\Agent\QuoteRequest;
use App\Http\Responses\AgentResponse;
use App\Services\Agent\AddressToken;
use App\Services\Agent\AddressTokenException;
use App\Services\Agent\CartAssembler;
use App\Services\Agent\CartAssemblyException;
use App\Services\Pricing\PricingService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/agent/quote
 *
 * Prices a cart and produces the sentence the agent reads back.
 *
 * This is one half of a design constraint the README states plainly: the order
 * is always read back before it is created. A caller has to hear what they are
 * about to be charged, itemised, before an order exists — which is why the
 * read-back is composed here from priced data rather than left to the model to
 * assemble from its memory of the conversation. A model summarising four
 * minutes of talk gets the total wrong occasionally, and "occasionally" is
 * measured in real money on somebody's card.
 *
 * Being below the minimum is not an error. It comes back as `ok: false` with
 * the shortfall attached, because the useful thing to say is "that's four
 * pounds fifty short, shall I add some sides?" rather than a refusal.
 */
final class QuoteController
{
    public function __construct(
        private CartAssembler $assembler,
        private PricingService $pricing,
        private AddressToken $tokens,
    ) {}

    public function __invoke(QuoteRequest $request): JsonResponse
    {
        $restaurant = $request->restaurant();
        $fulfilment = $request->fulfilment();
        $distance = null;

        if ($fulfilment === FulfilmentType::Delivery && ($token = $request->validated()['address_token'] ?? null)) {
            try {
                $distance = (int) $this->tokens->open((string) $token)['distance_metres'];
            } catch (AddressTokenException $exception) {
                return AgentResponse::fail(
                    $exception->errorCode,
                    "I've lost track of that address, sorry. Could you give it to me again?",
                );
            }
        }

        try {
            $cart = $this->assembler->assemble($restaurant, $fulfilment, $request->items(), $distance);
        } catch (CartAssemblyException $exception) {
            return AgentResponse::fail($exception->errorCode, $exception->say, $exception->data);
        }

        $priced = $this->pricing->price($cart);

        if (! $priced->meetsMinimum) {
            return AgentResponse::fail(
                AgentErrorCode::BelowMinimum,
                sprintf(
                    "That comes to %s, and our minimum for delivery is %s — you're %s short. Would you like to add anything?",
                    $priced->totalMoney()->spoken(),
                    $restaurant->minimumOrderValue()->spoken(),
                    $priced->shortfallMoney()->spoken(),
                ),
                $priced->toAgentArray(),
            );
        }

        return AgentResponse::ok($priced->toAgentArray(), $priced->spokenReadBack());
    }
}
