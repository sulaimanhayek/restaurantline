<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateAgent;

/**
 * The tool endpoints are on the public internet — ElevenLabs calls them from
 * its own infrastructure, so there is no network boundary to hide behind. The
 * bearer token is the only thing between a stranger with curl and this
 * restaurant's order table, which makes these the tests that matter most in
 * the phase.
 */
beforeEach(function (): void {
    $this->restaurant = alwaysOpen(restaurant());
});

/**
 * Every route, not a representative one. A tenth endpoint added later without
 * the middleware is exactly the mistake this catches, so the list is derived
 * from the router rather than typed out here.
 *
 * @return list<array{string, string}>
 */
function agentRoutes(): array
{
    return collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn ($route): bool => str_starts_with((string) $route->uri(), 'api/agent/'))
        ->map(static fn ($route): array => [
            in_array('GET', $route->methods(), true) ? 'GET' : 'POST',
            '/'.$route->uri(),
        ])
        ->values()
        ->all();
}

it('refuses every tool endpoint without a token', function (): void {
    expect(agentRoutes())->toHaveCount(9);

    foreach (agentRoutes() as [$method, $uri]) {
        $uri = str_replace('{order}', 'AB1234', $uri);

        $response = $method === 'GET'
            ? $this->getJson($uri)
            : $this->postJson($uri);

        expect($response->status())->toBe(401, $uri);
    }
});

it('refuses a wrong token', function (): void {
    agentGet('hours', token: 'not-the-token')->assertStatus(401);
});

it('refuses a token that is merely a prefix of the real one', function (): void {
    agentGet('hours', token: substr(AGENT_TEST_TOKEN, 0, 6))->assertStatus(401);
});

it('accepts the configured token', function (): void {
    agentGet('hours')->assertOk()->assertJsonPath('ok', true);
});

/**
 * The failure mode this exists to prevent: a forker deploys before setting
 * AGENT_API_TOKEN, and an empty configured token compares equal to an empty
 * supplied one. Open endpoints, silently.
 */
it('denies everything when no token is configured rather than letting everyone through', function (?string $configured): void {
    config()->set('restaurantline.agent.token', $configured);

    agentGet('hours')->assertStatus(401);
    agentGet('hours', token: '')->assertStatus(401);
    agentGet('hours', token: null)->assertStatus(401);
})->with([
    'unset' => [null],
    'empty' => [''],
]);

it('answers a rejection in the house shape, with something the agent can say', function (): void {
    agentGet('hours', token: 'wrong')
        ->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertJsonStructure(['ok', 'error' => ['code', 'say']]);
});

it('never leaks the configured token in a rejection', function (): void {
    $body = agentGet('hours', token: 'wrong')->getContent();

    expect($body)->not->toContain(AGENT_TEST_TOKEN);
});

/**
 * The other way to deploy an open endpoint, and the likelier one.
 *
 * An empty token at least looks unfinished. The value `.env.example` ships
 * looks configured — it is a long string with words in it — and it is printed
 * in a public repository, so an install using it is not protected by a secret,
 * it is protected by a password everybody has.
 */
it('refuses the token published in .env.example once the app is in production', function (): void {
    config()->set('restaurantline.agent.token', AuthenticateAgent::PLACEHOLDER_TOKEN);

    // On a laptop it is the whole point: it works.
    app()->detectEnvironment(fn (): string => 'local');
    agentGet('hours', token: AuthenticateAgent::PLACEHOLDER_TOKEN)->assertOk();

    app()->detectEnvironment(fn (): string => 'production');
    agentGet('hours', token: AuthenticateAgent::PLACEHOLDER_TOKEN)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('still accepts a real token in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    agentGet('hours')->assertOk()->assertJsonPath('ok', true);
});

it('says the example token is the problem, without repeating it back', function (): void {
    config()->set('restaurantline.agent.token', AuthenticateAgent::PLACEHOLDER_TOKEN);
    app()->detectEnvironment(fn (): string => 'production');

    $body = (string) agentGet('hours', token: AuthenticateAgent::PLACEHOLDER_TOKEN)->getContent();

    expect($body)->toContain('example agent token')
        ->and($body)->not->toContain(AuthenticateAgent::PLACEHOLDER_TOKEN);
});
