<?php

declare(strict_types=1);

use App\Enums\AgentErrorCode;
use App\Enums\GeocodeProvider;
use App\Services\Agent\AddressToken;
use App\Services\Agent\AddressTokenException;
use App\Services\Geocoding\AddressCandidate;
use App\Services\Geocoding\GeocodeCandidate;
use Illuminate\Support\Facades\Crypt;

/**
 * The seal that stops a language model inventing a delivery address.
 *
 * `/orders` accepts a delivery address in no other form, so everything this
 * class refuses is an address that will not reach a driver. Each refusal is
 * worth a test on its own: the whole point of the seal is that the failure it
 * prevents is invisible until somebody is standing outside the wrong house.
 *
 * A feature test rather than a unit one because the encrypter needs an APP_KEY,
 * and a token that survives a round trip with a hand-rolled key would prove
 * nothing about the one a forker will actually run.
 */
function candidate(string $line1 = '3 Hanbury Street', int $metres = 900, bool $within = true): AddressCandidate
{
    return new AddressCandidate(
        geocode: new GeocodeCandidate(
            formattedAddress: $line1.', London, E1 6QR, UK',
            latitude: 51.5202,
            longitude: -0.0738,
            confidence: 0.95,
            provider: GeocodeProvider::Fake,
            line1: $line1,
            city: 'London',
            postcode: 'E1 6QR',
            country: 'GB',
        ),
        distanceMetres: $metres,
        withinDeliveryRadius: $within,
    );
}

beforeEach(function (): void {
    $this->tokens = app(AddressToken::class);
});

it('carries the address back intact', function (): void {
    $payload = $this->tokens->open($this->tokens->issue(candidate(), 'three hanbury street'));

    expect($payload['address']['line_1'])->toBe('3 Hanbury Street')
        ->and($payload['address']['postcode'])->toBe('E1 6QR')
        ->and($payload['distance_metres'])->toBe(900)
        ->and($payload['within_delivery_area'])->toBeTrue();
});

/**
 * The two strings are not the same string and the difference is the whole
 * reason `raw_spoken` exists. `spoken` is the tidy readback; `raw_spoken` is
 * what the caller said, including the part the geocoder threw away, and it is
 * the only copy of it that survives to the Address row.
 */
it('keeps the caller\'s own words alongside the geocoder\'s rendering', function (): void {
    $payload = $this->tokens->open(
        $this->tokens->issue(candidate(), 'three hanbury street, blue gate past the chippy'),
    );

    expect($payload['raw_spoken'])->toBe('three hanbury street, blue gate past the chippy')
        ->and($payload['spoken'])->not->toBe($payload['raw_spoken']);
});

it('reveals nothing to somebody reading it off a log line', function (): void {
    $token = $this->tokens->issue(candidate(), 'three hanbury street');

    expect($token)->not->toContain('Hanbury')
        ->not->toContain('E1 6QR')
        ->not->toContain('51.52');
});

describe('what it refuses', function (): void {
    /**
     * The tamper that matters. Distance sets the delivery fee and decides
     * whether the address is in range at all, so a model that could edit it
     * could talk its way into a cheaper delivery to an address we do not serve.
     */
    it('refuses a token with a byte changed inside it', function (): void {
        $token = $this->tokens->issue(candidate(metres: 9000, within: false), 'somewhere far away');
        $tampered = substr_replace($token, $token[40] === 'a' ? 'b' : 'a', 40, 1);

        expect(fn () => $this->tokens->open($tampered))
            ->toThrow(AddressTokenException::class);
    });

    it('refuses a token this installation did not issue', function (): void {
        expect(fn () => $this->tokens->open('not-a-token-at-all'))
            ->toThrow(AddressTokenException::class);
    });

    /**
     * Sealed by us, but not shaped like one of ours — a token from an older
     * version of this application, most plausibly. The keys the return type
     * promises are checked rather than assumed, so this surfaces as an
     * unreadable token rather than as a missing array offset three layers down
     * in the order endpoint.
     */
    it('refuses a token of ours that is missing a key', function (): void {
        $token = Crypt::encryptString((string) json_encode([
            'iat' => time(),
            'address' => ['line_1' => '3 Hanbury Street'],
            'spoken' => '3 Hanbury Street, London, E1 6QR',
            'within_delivery_area' => true,
            'distance_metres' => 900,
        ]));

        expect(fn () => $this->tokens->open($token))
            ->toThrow(AddressTokenException::class);
    });

    /**
     * A token only has to outlive one phone call. Past the TTL it is a
     * scrapable string with an address in it and no remaining use, so it stops
     * being accepted rather than staying valid forever.
     */
    it('refuses a token older than its ttl', function (): void {
        config(['restaurantline.agent.address_token_ttl' => 60]);

        $token = $this->tokens->issue(candidate(), 'three hanbury street');

        $this->travel(61)->seconds();

        try {
            $this->tokens->open($token);
            $this->fail('An expired token was accepted.');
        } catch (AddressTokenException $exception) {
            // Distinct from the invalid code on purpose: an expired token means
            // the call has gone on a long time and the agent should re-confirm,
            // where an invalid one means something is wrong with the wiring.
            expect($exception->errorCode)->toBe(AgentErrorCode::AddressTokenExpired);
        }
    });

    it('accepts a token still inside its ttl', function (): void {
        config(['restaurantline.agent.address_token_ttl' => 3600]);

        $token = $this->tokens->issue(candidate(), 'three hanbury street');

        $this->travel(59)->minutes();

        expect($this->tokens->open($token)['address']['line_1'])->toBe('3 Hanbury Street');
    });
});
