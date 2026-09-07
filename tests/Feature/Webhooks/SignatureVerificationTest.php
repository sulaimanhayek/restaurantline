<?php

declare(strict_types=1);

use App\Jobs\ProcessElevenLabsWebhook;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeElevenLabsSignature;
use Tests\Support\WebhookPayloads;

/**
 * The only thing between a public URL and the transcript table.
 *
 * The webhook URL is not a secret — it lives in the ElevenLabs dashboard, in
 * the provisioning output, and in whatever ngrok log the forker left open. So
 * every test here is a test of the sole access control, and each one describes
 * a request that must not be allowed to write a row.
 */
beforeEach(function (): void {
    $this->restaurant = restaurant();
    config(['restaurantline.elevenlabs.webhook_secret' => 'whsec_test_secret']);
    Queue::fake();
});

it('accepts a correctly signed webhook', function (): void {
    postWebhook(WebhookPayloads::transcription())
        ->assertOk()
        ->assertJsonPath('ok', true);

    Queue::assertPushed(ProcessElevenLabsWebhook::class);
});

describe('what it rejects', function (): void {
    it('rejects a request with no signature at all', function (): void {
        $this->postJson('/webhooks/elevenlabs', WebhookPayloads::transcription())
            ->assertUnauthorized();

        Queue::assertNothingPushed();
    });

    it('rejects a signature computed with the wrong secret', function (): void {
        $body = (string) json_encode(WebhookPayloads::transcription());

        postWebhook(
            WebhookPayloads::transcription(),
            signature: FakeElevenLabsSignature::header($body, 'whsec_not_the_secret'),
        )->assertUnauthorized();

        Queue::assertNothingPushed();
    });

    /**
     * The attack the signature exists to stop: a genuine, correctly signed
     * payload with the body edited. Everything about the request looks right
     * except the one part that matters.
     */
    it('rejects a body edited after signing', function (): void {
        $signed = (string) json_encode(WebhookPayloads::transcription('conv-1'));
        $header = FakeElevenLabsSignature::header($signed, 'whsec_test_secret');

        $tampered = (string) json_encode(WebhookPayloads::transcription('conv-2'));

        $this->call(
            'POST',
            '/webhooks/elevenlabs',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ELEVENLABS_SIGNATURE' => $header],
            content: $tampered,
        )->assertUnauthorized();

        Queue::assertNothingPushed();
    });

    /**
     * Replay. A signature captured from a real webhook stays valid forever
     * without this, and a captured "the caller ordered nothing" can then be
     * posted over any later call.
     */
    it('rejects a signature older than the tolerance', function (): void {
        config(['restaurantline.elevenlabs.webhook_tolerance' => 1800]);

        postWebhook(WebhookPayloads::transcription(), timestamp: now()->getTimestamp() - 1801)
            ->assertUnauthorized();

        Queue::assertNothingPushed();
    });

    it('accepts a signature just inside the tolerance', function (): void {
        config(['restaurantline.elevenlabs.webhook_tolerance' => 1800]);

        postWebhook(WebhookPayloads::transcription(), timestamp: now()->getTimestamp() - 1799)
            ->assertOk();
    });

    /**
     * The timestamp is inside the signed payload, so editing it to escape the
     * staleness check invalidates the hash. This proves the two checks are
     * genuinely bound together rather than merely both present.
     */
    it('rejects a stale signature with the timestamp updated to look fresh', function (): void {
        $body = (string) json_encode(WebhookPayloads::transcription());
        $stale = now()->getTimestamp() - 7200;
        $header = FakeElevenLabsSignature::header($body, 'whsec_test_secret', $stale);

        // Same hash, fresher `t`. Exactly what an attacker with a captured
        // header would try first.
        $forged = preg_replace('/^t=\d+/', 't='.now()->getTimestamp(), $header);

        postWebhook(WebhookPayloads::transcription(), signature: (string) $forged)
            ->assertUnauthorized();
    });

    it('rejects a malformed signature header', function (string $header): void {
        postWebhook(WebhookPayloads::transcription(), signature: $header)->assertUnauthorized();
    })->with([
        'no parts' => 'garbage',
        'no timestamp' => 'v0=abc123',
        'no hash' => 't=1788000000',
        'empty hash' => 't=1788000000,v0=',
        'non-numeric timestamp' => 't=yesterday,v0=abc123',
    ]);

    /**
     * Fails closed. A fresh clone has no secret set, and treating "not
     * configured" as "nothing to check" would leave the state every forker
     * starts in accepting anything the internet sends it.
     */
    it('rejects everything when no secret is configured', function (): void {
        config(['restaurantline.elevenlabs.webhook_secret' => null]);

        $this->postJson('/webhooks/elevenlabs', WebhookPayloads::transcription(), [
            'ElevenLabs-Signature' => 't=1,v0=abc',
        ])->assertUnauthorized();

        Queue::assertNothingPushed();
    });
});

/**
 * Nothing about why. Telling an unauthenticated caller whether their timestamp
 * was stale or their hash was wrong tells them which half to fix.
 */
it('says nothing about why it refused', function (): void {
    $response = postWebhook(WebhookPayloads::transcription(), signature: 't=1,v0=abc');

    expect($response->getContent())
        ->not->toContain('timestamp')
        ->not->toContain('signature')
        ->not->toContain('secret');
});

/**
 * Header names are case-insensitive in HTTP and ElevenLabs' own documentation
 * writes this one lowercase while their examples capitalise it. Both must work.
 */
it('reads the signature header whatever its casing', function (string $header): void {
    $body = (string) json_encode(WebhookPayloads::transcription());

    $this->call(
        'POST',
        '/webhooks/elevenlabs',
        server: [
            'CONTENT_TYPE' => 'application/json',
            $header => FakeElevenLabsSignature::header($body, 'whsec_test_secret'),
        ],
        content: $body,
    )->assertOk();
})->with([
    'upper' => 'HTTP_ELEVENLABS_SIGNATURE',
    'mixed' => 'HTTP_ElevenLabs_Signature',
]);

/**
 * Registered outside the web middleware group, so there is no session and no
 * token to fail on. A CSRF rejection here would look exactly like a signature
 * failure and waste an afternoon.
 */
it('is not subject to CSRF', function (): void {
    postWebhook(WebhookPayloads::transcription())->assertOk();
})->withoutMiddleware(ValidateCsrfToken::class);
