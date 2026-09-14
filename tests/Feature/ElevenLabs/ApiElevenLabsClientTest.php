<?php

declare(strict_types=1);

use App\Services\ElevenLabs\ApiElevenLabsClient;
use App\Services\ElevenLabs\ElevenLabsException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The only file in the repo that makes an HTTP call to ElevenLabs, held to the
 * shapes their OpenAPI document actually describes.
 *
 * This is a transcription test and it is meant to be. There is no PHP SDK, so
 * every header name, every path and every wrapper key in `ApiElevenLabsClient`
 * was copied by hand out of `api.elevenlabs.io/openapi.json`, and a typo in any
 * of them fails at the worst possible moment: against a real workspace, with a
 * developer halfway through their first hour, reading a 404 that says nothing
 * about which of nine calls was wrong.
 *
 * The header is the one worth spelling out. It is `xi-api-key`. Several of the
 * rendered API-reference pages say `Authorization: Bearer`, and they are wrong;
 * the authentication page and the OpenAPI document agree with each other.
 * See docs/DECISIONS.md #0040.
 */
function elevenLabs(): ApiElevenLabsClient
{
    return new ApiElevenLabsClient;
}

/**
 * The single request that was sent, for a test that only made one.
 */
function sentRequest(): Request
{
    $recorded = Http::recorded()->all();

    expect($recorded)->toHaveCount(1);

    /** @var Request $request */
    $request = $recorded[0][0];

    return $request;
}

/**
 * @return array<string, mixed>
 */
function sentBody(): array
{
    /** @var array<string, mixed> $data */
    $data = sentRequest()->data();

    return $data;
}

beforeEach(function (): void {
    config([
        'restaurantline.elevenlabs.driver' => 'api',
        'restaurantline.elevenlabs.api_key' => 'xi-key-for-tests',
        'restaurantline.elevenlabs.base_url' => 'https://api.elevenlabs.io',
    ]);

    Http::preventStrayRequests();
});

describe('how it authenticates', function (): void {
    it('sends the key as xi-api-key and never as a bearer token', function (): void {
        Http::fake(['*' => Http::response(['id' => 'tool_1'])]);

        elevenLabs()->createTool(['name' => 'lookup_menu']);

        $request = sentRequest();

        expect($request->header('xi-api-key'))->toBe(['xi-key-for-tests'])
            ->and($request->hasHeader('Authorization'))->toBeFalse();
    });

    it('refuses to send anything without a key', function (): void {
        config(['restaurantline.elevenlabs.api_key' => '']);

        Http::fake();

        expect(fn () => elevenLabs()->listSecrets())
            ->toThrow(ElevenLabsException::class, 'ELEVENLABS_API_KEY is empty');

        Http::assertNothingSent();
    });

    it('talks to whatever base URL it is pointed at', function (): void {
        config(['restaurantline.elevenlabs.base_url' => 'https://proxy.example.com/']);

        Http::fake(['proxy.example.com/*' => Http::response(['id' => 'tool_1'])]);

        elevenLabs()->createTool(['name' => 'lookup_menu']);

        expect(sentRequest()->url())->toBe('https://proxy.example.com/v1/convai/tools');
    });
});

describe('tools', function (): void {
    it('wraps a tool config in tool_config and returns the new id', function (): void {
        Http::fake(['*' => Http::response(['id' => 'tool_abc'])]);

        $id = elevenLabs()->createTool(['name' => 'lookup_menu', 'description' => 'Look up a dish']);

        expect($id)->toBe('tool_abc')
            ->and(sentRequest()->url())->toBe('https://api.elevenlabs.io/v1/convai/tools')
            ->and(sentRequest()->method())->toBe('POST')
            ->and(sentBody())->toBe(['tool_config' => ['name' => 'lookup_menu', 'description' => 'Look up a dish']]);
    });

    it('patches an existing tool by id', function (): void {
        Http::fake(['*' => Http::response(['id' => 'tool_abc'])]);

        $id = elevenLabs()->updateTool('tool_abc', ['name' => 'lookup_menu']);

        expect($id)->toBe('tool_abc')
            ->and(sentRequest()->url())->toBe('https://api.elevenlabs.io/v1/convai/tools/tool_abc')
            ->and(sentRequest()->method())->toBe('PATCH')
            ->and(sentBody())->toBe(['tool_config' => ['name' => 'lookup_menu']]);
    });

    it('reads a tool back', function (): void {
        Http::fake(['*' => Http::response(['id' => 'tool_abc', 'tool_config' => ['name' => 'lookup_menu']])]);

        expect(elevenLabs()->getTool('tool_abc'))
            ->toBe(['id' => 'tool_abc', 'tool_config' => ['name' => 'lookup_menu']]);
    });

    it('treats a missing tool as absent rather than as a failure', function (): void {
        Http::fake(['*' => Http::response(['detail' => 'not found'], 404)]);

        expect(elevenLabs()->getTool('tool_deleted_in_the_dashboard'))->toBeNull();
    });

    /*
     * The important half of that behaviour. A 401 read as "no such tool" would
     * make provisioning cheerfully create all nine again in a workspace it has
     * no access to, and report success for every one of them.
     */
    it('does not treat a 401 as a missing tool', function (): void {
        Http::fake(['*' => Http::response(['detail' => 'invalid api key'], 401)]);

        expect(fn () => elevenLabs()->getTool('tool_abc'))
            ->toThrow(ElevenLabsException::class, 'invalid api key');
    });
});

describe('agents', function (): void {
    /*
     * Note the path. Creating an agent is a POST to /agents/create, not to the
     * collection, which is the single easiest thing to get wrong here.
     */
    it('creates an agent at the /create path and sends the payload whole', function (): void {
        Http::fake(['*' => Http::response(['agent_id' => 'agent_abc'])]);

        $id = elevenLabs()->createAgent(['name' => 'Ember Grill', 'conversation_config' => ['agent' => []]]);

        expect($id)->toBe('agent_abc')
            ->and(sentRequest()->url())->toBe('https://api.elevenlabs.io/v1/convai/agents/create')
            ->and(sentBody())->toBe(['name' => 'Ember Grill', 'conversation_config' => ['agent' => []]]);
    });

    it('patches an agent by id', function (): void {
        Http::fake(['*' => Http::response(['agent_id' => 'agent_abc'])]);

        elevenLabs()->updateAgent('agent_abc', ['name' => 'Ember Grill']);

        expect(sentRequest()->url())->toBe('https://api.elevenlabs.io/v1/convai/agents/agent_abc')
            ->and(sentRequest()->method())->toBe('PATCH')
            ->and(sentBody())->toBe(['name' => 'Ember Grill']);
    });

    it('treats a missing agent as absent', function (): void {
        Http::fake(['*' => Http::response([], 404)]);

        expect(elevenLabs()->getAgent('agent_deleted'))->toBeNull();
    });
});

describe('secrets', function (): void {
    it('creates a secret with the type the API insists on', function (): void {
        Http::fake(['*' => Http::response(['secret_id' => 'secret_abc', 'name' => 'restaurantline_ember_authorization'])]);

        $id = elevenLabs()->createSecret('restaurantline_ember_authorization', 'Bearer token-one');

        expect($id)->toBe('secret_abc')
            ->and(sentRequest()->url())->toBe('https://api.elevenlabs.io/v1/convai/secrets')
            ->and(sentBody())->toBe([
                'type' => 'new',
                'name' => 'restaurantline_ember_authorization',
                'value' => 'Bearer token-one',
            ]);
    });

    /*
     * All three fields, every time. The update body is not a patch of the
     * fields you name — omitting the name on an update is a 422, and this is
     * the call that makes rotating AGENT_API_TOKEN a one-line change.
     */
    it('updates a secret with type, name and value together', function (): void {
        Http::fake(['*' => Http::response([])]);

        elevenLabs()->updateSecret('secret_abc', 'restaurantline_ember_authorization', 'Bearer token-two');

        expect(sentRequest()->url())->toBe('https://api.elevenlabs.io/v1/convai/secrets/secret_abc')
            ->and(sentRequest()->method())->toBe('PATCH')
            ->and(sentBody())->toBe([
                'type' => 'update',
                'name' => 'restaurantline_ember_authorization',
                'value' => 'Bearer token-two',
            ]);
    });

    it('lists secrets as a map of name to id', function (): void {
        Http::fake(['*' => Http::response(['secrets' => [
            ['secret_id' => 'secret_one', 'name' => 'restaurantline_ember_authorization'],
            ['secret_id' => 'secret_two', 'name' => 'something_else'],
        ]])]);

        expect(elevenLabs()->listSecrets())->toBe([
            'restaurantline_ember_authorization' => 'secret_one',
            'something_else' => 'secret_two',
        ]);
    });

    it('survives a workspace with no secrets in it', function (): void {
        Http::fake(['*' => Http::response([])]);

        expect(elevenLabs()->listSecrets())->toBe([]);
    });
});

describe('webhooks', function (): void {
    it('creates an HMAC webhook and hands back the one-time secret', function (): void {
        Http::fake(['*' => Http::response(['webhook_id' => 'wh_abc', 'webhook_secret' => 'wsec_123'])]);

        $created = elevenLabs()->createWebhook('restaurantline — Ember Grill post-call', 'https://ember.example/webhooks/elevenlabs');

        expect($created)->toBe(['webhook_id' => 'wh_abc', 'webhook_secret' => 'wsec_123'])
            ->and(sentRequest()->url())->toBe('https://api.elevenlabs.io/v1/workspace/webhooks')
            ->and(sentBody())->toBe(['settings' => [
                'auth_type' => 'hmac',
                'name' => 'restaurantline — Ember Grill post-call',
                'webhook_url' => 'https://ember.example/webhooks/elevenlabs',
            ]]);
    });

    /*
     * The secret comes back once and only on the call that created it. A
     * response without one is not an error — it is the caller's cue to say so
     * rather than to print an empty box.
     */
    it('reports no secret rather than an empty one', function (): void {
        Http::fake(['*' => Http::response(['webhook_id' => 'wh_abc', 'webhook_secret' => ''])]);

        expect(elevenLabs()->createWebhook('name', 'https://ember.example/webhooks/elevenlabs')['webhook_secret'])
            ->toBeNull();
    });

    it('lists webhooks keyed by the URL they post to', function (): void {
        Http::fake(['*' => Http::response(['webhooks' => [
            ['webhook_id' => 'wh_abc', 'webhook_url' => 'https://ember.example/webhooks/elevenlabs'],
        ]])]);

        expect(elevenLabs()->listWebhooks())
            ->toBe(['https://ember.example/webhooks/elevenlabs' => 'wh_abc']);
    });
});

describe('phone numbers', function (): void {
    it('imports a Twilio number and points it at the agent in one call', function (): void {
        Http::fake(['*' => Http::response(['phone_number_id' => 'phnum_abc'])]);

        $id = elevenLabs()->importTwilioNumber(
            phoneNumber: '+442079460000',
            label: 'Ember Grill',
            accountSid: 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            authToken: 'twilio-auth-token',
            agentId: 'agent_abc',
        );

        expect($id)->toBe('phnum_abc')
            ->and(sentRequest()->url())->toBe('https://api.elevenlabs.io/v1/convai/phone-numbers')
            ->and(sentBody())->toBe([
                'provider' => 'twilio',
                'phone_number' => '+442079460000',
                'label' => 'Ember Grill',
                'sid' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
                'token' => 'twilio-auth-token',
                'agent_id' => 'agent_abc',
            ]);
    });

    it('leaves agent_id out rather than sending a null', function (): void {
        Http::fake(['*' => Http::response(['phone_number_id' => 'phnum_abc'])]);

        elevenLabs()->importTwilioNumber(
            phoneNumber: '+442079460000',
            label: 'Ember Grill',
            accountSid: 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            authToken: 'twilio-auth-token',
        );

        expect(sentBody())->not->toHaveKey('agent_id');
    });
});

describe('when it goes wrong', function (): void {
    /*
     * The whole reason this class digs through the error body. A failed
     * provision is read by a person, and "url  field required" names the one
     * malformed property out of nine tools; "POST /v1/convai/tools returned
     * HTTP 422" sends them to read nine files.
     */
    it('reads a FastAPI validation error down to the field', function (): void {
        Http::fake(['*' => Http::response(['detail' => [
            ['loc' => ['body', 'tool_config', 'api_schema', 'url'], 'msg' => 'field required'],
        ]], 422)]);

        expect(fn () => elevenLabs()->createTool([]))
            ->toThrow(ElevenLabsException::class, 'body.tool_config.api_schema.url field required');
    });

    it('reads a business rejection out of detail.message', function (): void {
        Http::fake(['*' => Http::response(['detail' => ['message' => 'You have reached your tool limit.']], 400)]);

        expect(fn () => elevenLabs()->createTool([]))
            ->toThrow(ElevenLabsException::class, 'You have reached your tool limit.');
    });

    it('falls back to the raw body when nothing in it looks like an explanation', function (): void {
        Http::fake(['*' => Http::response('<html><body>502 Bad Gateway</body></html>', 502)]);

        expect(fn () => elevenLabs()->createTool([]))
            ->toThrow(ElevenLabsException::class, '502 Bad Gateway');
    });

    it('carries the status and the body for whatever prints them', function (): void {
        Http::fake(['*' => Http::response(['detail' => 'nope'], 422)]);

        try {
            elevenLabs()->createTool([]);
        } catch (ElevenLabsException $exception) {
            expect($exception->status)->toBe(422)
                ->and($exception->body)->toContain('nope')
                ->and($exception->getMessage())->toContain('POST /v1/convai/tools');

            return;
        }

        $this->fail('A 422 should have thrown.');
    });

    it('says it could not reach ElevenLabs rather than leaking a connection error', function (): void {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        expect(fn () => elevenLabs()->createAgent([]))
            ->toThrow(ElevenLabsException::class, 'Could not reach ElevenLabs');
    });

    /*
     * A 200 with nothing usable in it. Rare, and worth its own message: the
     * alternative is storing an empty string as the agent id and discovering
     * it on the next run, three steps from the cause.
     */
    it('complains when a success comes back without the id it promised', function (): void {
        Http::fake(['*' => Http::response(['status' => 'ok'])]);

        expect(fn () => elevenLabs()->createAgent([]))
            ->toThrow(ElevenLabsException::class, 'returned no "agent_id"');
    });
});
