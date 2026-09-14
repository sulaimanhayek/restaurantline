<?php

declare(strict_types=1);

use App\Models\OpeningHour;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeElevenLabsSignature;
use Tests\Support\FakeStripeSignature;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every test gets a fresh PostgreSQL schema. Slower than SQLite in memory, and
| worth it: the menu matcher and the availability queries are PostgreSQL-shaped,
| and a suite that green-lights a query the production database would reject is
| the most expensive kind of passing test.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * The restaurant every test hangs off.
 *
 * Returns the one already in the database if there is one, so a test that
 * needs three menu items does not silently create three restaurants and then
 * fail a scoped query for reasons that take an hour to find.
 *
 * @param  array<string, mixed>  $attributes
 */
function restaurant(array $attributes = []): Restaurant
{
    if ($attributes !== []) {
        return Restaurant::factory()->create($attributes);
    }

    return Restaurant::query()->first()
        ?? Restaurant::factory()->create();
}

/*
|--------------------------------------------------------------------------
| Agent endpoint helpers
|--------------------------------------------------------------------------
|
| Every test that hits a tool endpoint does it as ElevenLabs does: over HTTP,
| with a bearer token. The token is configured rather than faked, so the
| middleware under test is the one that runs in production.
|
| The hook covers the whole of Feature rather than Feature/Agent, because the
| agent endpoints are how an order comes into existence — a test about payment,
| the kitchen display or a webhook reaches for `agentPost` to set one up, and
| scoping the token to one directory means those get a 401, an empty order
| number, a 404 from a URL with a hole in it, and an afternoon.
|
*/

const AGENT_TEST_TOKEN = 'test-agent-token';

pest()->beforeEach(function (): void {
    config()->set('restaurantline.agent.token', AGENT_TEST_TOKEN);
})->in('Feature');

/**
 * A POST to a tool endpoint, authenticated unless `$token` says otherwise.
 *
 * @param  array<string, mixed>  $payload
 * @return TestResponse<Response>
 */
function agentPost(string $uri, array $payload = [], ?string $token = AGENT_TEST_TOKEN): TestResponse
{
    $uri = '/api/agent/'.ltrim($uri, '/');

    /** @var TestResponse<Response> $response */
    $response = $token === null
        ? test()->postJson($uri, $payload)
        : test()->withToken($token)->postJson($uri, $payload);

    return $response;
}

/**
 * @param  array<string, mixed>  $query
 * @return TestResponse<Response>
 */
function agentGet(string $uri, array $query = [], ?string $token = AGENT_TEST_TOKEN): TestResponse
{
    $uri = '/api/agent/'.ltrim($uri, '/').($query === [] ? '' : '?'.http_build_query($query));

    /** @var TestResponse<Response> $response */
    $response = $token === null
        ? test()->getJson($uri)
        : test()->withToken($token)->getJson($uri);

    return $response;
}

/**
 * A sealed address token, obtained the way the agent obtains one.
 *
 * Deliberately goes through the endpoint rather than calling AddressToken
 * directly: a test that mints its own token would keep passing if
 * /address/validate stopped issuing them.
 */
function addressToken(string $spoken = '3 Hanbury Street, E1 6QR'): string
{
    return (string) agentPost('address/validate', [
        'conversation_id' => 'call-1',
        'spoken' => $spoken,
    ])->json('candidates.0.address_token');
}

/**
 * A complete /orders body against the DemoMenu fixture.
 *
 * Two Ember Chicken Burgers, collection, one caller — comfortably over the
 * minimum and free of anything a test might want to vary by accident. Override
 * only the field under test, so a failure names the reason.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function orderPayload(array $overrides = []): array
{
    return array_replace([
        'conversation_id' => 'call-1',
        'fulfilment' => 'collection',
        'customer' => ['phone_number' => '+447700900123', 'name' => 'Sam'],
        'items' => [['item' => 'ember-chicken-burger', 'quantity' => 2]],
    ], $overrides);
}

/**
 * Opening hours covering every hour of every day.
 *
 * Most tool endpoints have nothing to do with the clock, and a suite that goes
 * red at 11pm because the seeded kitchen shut is a suite people stop trusting.
 * The tests that are about opening hours set their own.
 */
function alwaysOpen(Restaurant $restaurant): Restaurant
{
    foreach (range(0, 6) as $dayOfWeek) {
        OpeningHour::factory()->for($restaurant)->create([
            'day_of_week' => $dayOfWeek,
            'opens_at' => '00:00:00',
            'closes_at' => '00:00:00',
            'closes_next_day' => true,
        ]);
    }

    return $restaurant->refresh();
}

/**
 * A JSON list of rows, narrowed to something worth plucking a column out of.
 *
 * `TestResponse::json()` is typed `mixed`, which is honest — a response body
 * really could be anything — and useless the moment a test wants
 * `->pluck('item')`. Narrowing happens here rather than at each call site, and
 * asserts rather than assumes: a test that pulls `matches` out of an error
 * response should fail on the shape, not on a null further down.
 *
 * @return Collection<int, array<string, mixed>>
 */
function rows(mixed $value): Collection
{
    expect($value)->toBeArray();

    /** @var array<int, array<string, mixed>> $value */
    return collect($value);
}

/**
 * A signed post-call webhook, posted the way ElevenLabs posts one.
 *
 * The body is encoded once and both signed and sent as that exact string.
 * Signing a re-encoded copy would be the one mistake this test suite exists to
 * catch — the middleware hashes the raw body, and any test that lets the two
 * diverge would pass while production failed.
 *
 * @param  array<string, mixed>  $payload
 * @return TestResponse<Response>
 */
function postWebhook(array $payload, ?string $signature = null, ?int $timestamp = null): TestResponse
{
    $body = (string) json_encode($payload);

    /** @var TestResponse<Response> $response */
    $response = test()->call(
        'POST',
        '/webhooks/elevenlabs',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ELEVENLABS_SIGNATURE' => $signature ?? FakeElevenLabsSignature::header(
                $body,
                (string) config('restaurantline.elevenlabs.webhook_secret'),
                $timestamp,
            ),
        ],
        content: $body,
    );

    return $response;
}

/**
 * A signed Stripe webhook, posted the way Stripe posts one.
 *
 * `$secrets` is a list rather than a string so the rotation case is reachable:
 * Stripe signs with every active endpoint secret during a rollover, and the
 * header carries a `v1` for each. A verifier that reads only the first would
 * pass every test written against a single secret and reject half of a real
 * rollover's traffic.
 *
 * @param  array<string, mixed>  $payload
 * @param  list<string>  $secrets
 * @return TestResponse<Response>
 */
function postStripeWebhook(
    array $payload,
    ?string $signature = null,
    array $secrets = ['whsec_stripe_test_secret'],
    ?int $timestamp = null,
): TestResponse {
    $body = (string) json_encode($payload);

    /** @var TestResponse<Response> $response */
    $response = test()->call(
        'POST',
        '/webhooks/stripe',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature ?? FakeStripeSignature::header($body, $secrets, $timestamp),
        ],
        content: $body,
    );

    return $response;
}
