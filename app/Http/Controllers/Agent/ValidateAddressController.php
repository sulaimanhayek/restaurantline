<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Enums\AgentErrorCode;
use App\Http\Requests\Agent\ValidateAddressRequest;
use App\Http\Responses\AgentResponse;
use App\Services\Agent\AddressToken;
use App\Services\Geocoding\AddressCandidate;
use App\Services\Geocoding\AddressValidator;
use App\Support\Distance;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/agent/address/validate
 *
 * Turns what a caller said into candidate addresses, and hands each one back
 * sealed so it can come back on the order (#0019).
 *
 * Nothing is persisted here. An address becomes a row only when an order is
 * created, which keeps the table free of the half-heard attempts a caller makes
 * before landing on the right one.
 *
 * The three outcomes the agent has to tell apart, and they are separate codes
 * because each leads somewhere different:
 *
 *  - nothing found         → ask for a postcode
 *  - several found         → ask which
 *  - found but out of range→ offer collection
 */
final class ValidateAddressController
{
    public function __construct(
        private AddressValidator $validator,
        private AddressToken $tokens,
    ) {}

    public function __invoke(ValidateAddressRequest $request): JsonResponse
    {
        $restaurant = $request->restaurant();
        $spoken = (string) $request->validated()['spoken'];

        $result = $this->validator->validate($restaurant, $spoken);

        if ($result->isEmpty()) {
            return AgentResponse::fail(
                AgentErrorCode::AddressNotFound,
                "I couldn't find that address. Could you give me your postcode?",
                $result->toAgentArray(),
            );
        }

        $payload = $result->toAgentArray();

        // Every candidate is sealed, not just the best one. The caller may well
        // pick the second, and re-validating to get a token for it would mean
        // asking them to say the address again.
        $payload['candidates'] = array_map(
            fn (AddressCandidate $candidate, array $shape): array => $shape + [
                'address_token' => $this->tokens->issue($candidate, $spoken),
            ],
            $result->candidates,
            $payload['candidates'],
        );

        if ($result->isOutsideDeliveryArea()) {
            $best = $result->best();

            return AgentResponse::fail(
                AgentErrorCode::OutsideDeliveryArea,
                sprintf(
                    "That's %s away, which is outside the area we deliver to. You're very welcome to collect.",
                    $best === null ? 'too far' : Distance::spoken($best->distanceMetres),
                ),
                $payload,
            );
        }

        if ($result->isAmbiguous()) {
            return AgentResponse::fail(
                AgentErrorCode::AddressAmbiguous,
                sprintf(
                    'I found a few that could match. Is it %s?',
                    $this->orList(array_map(
                        static fn (AddressCandidate $candidate): string => $candidate->spoken(),
                        $result->alternatives(),
                    )),
                ),
                $payload,
            );
        }

        return AgentResponse::ok($payload);
    }

    /**
     * @param  list<string>  $options
     */
    private function orList(array $options): string
    {
        if (count($options) === 1) {
            return $options[0];
        }

        $last = array_pop($options);

        return implode(', ', $options).' or '.$last;
    }
}
