<?php

declare(strict_types=1);

use App\Services\Sms\SmsSender;
use App\Services\Sms\TwilioSmsSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The one real SMS provider this repo ships.
 *
 * Every test here is against `Http::fake()`, never the network: a suite that
 * can fail because somebody else's API is having a bad morning is not a suite.
 * What is worth asserting is the request shape — Twilio's Messages endpoint is
 * form-encoded with HTTP basic auth and capitalised parameter names, and all
 * three are easy to get wrong in a way no type system catches.
 */
beforeEach(function (): void {
    /*
     * Twilio's own placeholder spelling, x's and all, rather than something
     * hex-shaped. A real account SID is `AC` followed by 32 hex characters, and
     * secret scanners match on exactly that — a convincing fake in a public
     * repository gets the push rejected and buys nothing, since the HTTP client
     * here is faked and never sees a credential either way.
     */
    config([
        'restaurantline.sms.driver' => 'twilio',
        'restaurantline.twilio.account_sid' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'restaurantline.twilio.auth_token' => 'token_secret',
        'restaurantline.twilio.from' => '+441134960000',
    ]);
});

/**
 * Twilio's response to a message it has accepted.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function twilioAccepted(array $overrides = []): array
{
    return array_replace([
        'sid' => 'SM0123456789abcdef0123456789abcdef',
        'status' => 'queued',
        'error_code' => null,
        'error_message' => null,
    ], $overrides);
}

it('posts the message to Twilio the way Twilio expects it', function (): void {
    Http::fake(['api.twilio.com/*' => Http::response(twilioAccepted(), 201)]);

    $result = (new TwilioSmsSender)->send('+447700900123', 'Ember Kitchen: order L-2356 confirmed.');

    expect($result->sent)->toBeTrue()
        ->and($result->providerMessageId)->toBe('SM0123456789abcdef0123456789abcdef')
        ->and($result->error)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx/Messages.json'
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx:token_secret'))
            && $request['To'] === '+447700900123'
            && $request['From'] === '+441134960000'
            && $request['Body'] === 'Ember Kitchen: order L-2356 confirmed.';
    });
});

describe('the ways a text does not go out', function (): void {
    /*
     * A half-configured install should still take orders. It just cannot text
     * about them, and the row this lands on says exactly that rather than
     * leaving somebody guessing why the customer never got a link.
     */
    it('reports a useful failure rather than throwing when Twilio is not configured', function (): void {
        config(['restaurantline.twilio.auth_token' => '']);
        Http::fake();

        $result = (new TwilioSmsSender)->send('+447700900123', 'Hello');

        expect($result->sent)->toBeFalse()
            ->and($result->error)->toContain('TWILIO_AUTH_TOKEN');

        Http::assertNothingSent();
    });

    /*
     * "The 'To' number is not a valid phone number" is worth a hundred times
     * more to the person in the dashboard than "400", so Twilio's own message
     * and code are what get recorded.
     */
    it('keeps the message Twilio gave for a rejection', function (): void {
        Http::fake(['api.twilio.com/*' => Http::response([
            'code' => 21211,
            'message' => "The 'To' number +447700900123 is not a valid phone number.",
            'status' => 400,
        ], 400)]);

        $result = (new TwilioSmsSender)->send('+447700900123', 'Hello');

        expect($result->sent)->toBeFalse()
            ->and($result->error)->toContain('not a valid phone number')
            ->and($result->error)->toContain('21211');
    });

    it('falls back to the status code when Twilio says nothing useful', function (): void {
        Http::fake(['api.twilio.com/*' => Http::response([], 503)]);

        expect((new TwilioSmsSender)->send('+447700900123', 'Hello')->error)
            ->toContain('503');
    });

    /*
     * Twilio 201s a message it has accepted and also, occasionally, one it has
     * already given up on — usually a bad From number or a suspended account.
     * A 2xx is not the same as a message on its way.
     */
    it('treats an accepted-but-failed message as a failure', function (): void {
        Http::fake(['api.twilio.com/*' => Http::response(twilioAccepted(['status' => 'failed']), 201)]);

        $result = (new TwilioSmsSender)->send('+447700900123', 'Hello');

        expect($result->sent)->toBeFalse()
            ->and($result->error)->toContain('failed');
    });

    it('survives not being able to reach Twilio at all', function (): void {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $result = (new TwilioSmsSender)->send('+447700900123', 'Hello');

        expect($result->sent)->toBeFalse()
            ->and($result->error)->toContain('Could not reach Twilio');
    });
});

it('is what the container hands out when SMS_DRIVER is twilio', function (): void {
    expect(app(SmsSender::class))->toBeInstanceOf(TwilioSmsSender::class)
        ->and(app(SmsSender::class)->name())->toBe('twilio');
});

it('refuses to boot on a driver nobody implemented', function (): void {
    config(['restaurantline.sms.driver' => 'carrier-pigeon']);

    expect(fn () => app(SmsSender::class))
        ->toThrow(InvalidArgumentException::class, 'carrier-pigeon');
});
