<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\AgentErrorCode;
use App\Services\Geocoding\AddressCandidate;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * A geocoded address, sealed so it can survive a round trip through the agent.
 *
 * `POST /address/validate` persists nothing — it hands back candidates. The
 * caller then agrees to one out loud, and `POST /orders` has to be told which.
 * Passing the address back as plain text would mean the order endpoint trusting
 * a street name that arrived from a language model, and a model that has just
 * failed to geocode "Fournier Street" is entirely capable of deciding the
 * caller probably meant Fournier Street anyway. The address on the ticket would
 * then be one nobody verified and the driver would find out first.
 *
 * So the candidate travels sealed. `POST /orders` will not accept a delivery
 * address in any other form, which means every address on an order provably
 * came out of the geocoder rather than out of the model.
 *
 * This is not the same guarantee as `verified_at`, and the two are deliberately
 * separate: the seal proves the address is *real*, `verified_at` records that
 * the agent asserted the caller *heard it read back and agreed*. Nothing
 * server-side can check the second one, so it is stored as what it is — a
 * timestamped assertion, traceable to a turn in the transcript.
 *
 * Laravel's encrypter is doing the work rather than a hand-rolled HMAC: it is
 * already authenticated, already keyed on `APP_KEY`, and already the thing a
 * forker's key rotation will rotate.
 *
 * @see docs/DECISIONS.md #0019
 */
final class AddressToken
{
    public function issue(AddressCandidate $candidate, string $rawSpoken): string
    {
        return Crypt::encryptString((string) json_encode([
            // Carbon's clock rather than time(), so the expiry below is reachable
            // from a test. A TTL nothing can travel past is a TTL nobody has
            // ever seen fire.
            'iat' => now()->getTimestamp(),
            'address' => $candidate->toAddressAttributes(),
            // Two different strings, and the difference matters. `spoken` is
            // the tidy readback the agent says out loud; `raw_spoken` is what
            // the caller actually said, which is the only record of the
            // "second door past the chippy, blue gate" that the geocoder threw
            // away. Address::$raw_spoken_text is that one, kept forever.
            'raw_spoken' => $rawSpoken,
            'spoken' => $candidate->spoken(),
            'within_delivery_area' => $candidate->withinDeliveryRadius,
            'distance_metres' => $candidate->distanceMetres,
        ]));
    }

    /**
     * @return array{iat: int, address: array<string, mixed>, raw_spoken: string, spoken: string, within_delivery_area: bool, distance_metres: int}
     *
     * @throws AddressTokenException
     */
    public function open(string $token): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            // Covers a truncated token, a token from another installation, and
            // a token from before an APP_KEY rotation. All three are the same
            // thing from the caller's point of view: ask for the address again.
            throw new AddressTokenException(
                AgentErrorCode::AddressTokenInvalid,
                'The address token could not be read.',
            );
        }

        // Every key the return type promises is checked, not just the two the
        // logic below reads. A payload missing one is a token from a different
        // version of this application, and the caller's recovery is the same as
        // for any other unreadable token: ask for the address again.
        $expected = ['iat', 'address', 'raw_spoken', 'spoken', 'within_delivery_area', 'distance_metres'];

        if (! is_array($decoded) || array_diff($expected, array_keys($decoded)) !== []) {
            throw new AddressTokenException(
                AgentErrorCode::AddressTokenInvalid,
                'The address token was not the expected shape.',
            );
        }

        $ttl = (int) config('restaurantline.agent.address_token_ttl', 3600);

        if (now()->getTimestamp() - (int) $decoded['iat'] > $ttl) {
            throw new AddressTokenException(
                AgentErrorCode::AddressTokenExpired,
                'The address token has expired.',
            );
        }

        /** @var array{iat: int, address: array<string, mixed>, raw_spoken: string, spoken: string, within_delivery_area: bool, distance_metres: int} $decoded */
        return $decoded;
    }
}
