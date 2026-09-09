<?php

declare(strict_types=1);

use App\Services\Geocoding\GeocodeCandidate;
use App\Services\Geocoding\Geocoder;
use Tests\Support\DemoMenu;

/**
 * The promises every agent endpoint makes, tested once rather than nine times.
 *
 * Two of them are load-bearing on a live phone call. The rate limiter is what
 * stops a looping agent from spending a restaurant's month of API budget in an
 * afternoon, and the exception renderer is what stops a caller hearing an
 * approximation of a stack trace read aloud.
 */
beforeEach(function (): void {
    $this->restaurant = alwaysOpen(restaurant());
    new DemoMenu($this->restaurant);
});

describe('rate limiting', function (): void {
    /**
     * A phone call is a handful of tool calls a minute. The limit sits far above
     * that on purpose — it is there to catch a runaway loop, not to shape
     * ordinary traffic — so the test lowers it rather than sending 120 requests.
     */
    it('refuses a conversation that has burned through its minute', function (): void {
        config(['restaurantline.agent.rate_limit_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            agentPost('menu/search', ['conversation_id' => 'call-1', 'query' => 'chips'])->assertOk();
        }

        agentPost('menu/search', ['conversation_id' => 'call-1', 'query' => 'chips'])
            ->assertStatus(429);
    });

    /**
     * The limit is keyed on the conversation, not the source address. Every call
     * arrives from the same handful of ElevenLabs egress IPs, so an IP-keyed
     * limiter would have one busy caller silencing the restaurant's whole line.
     */
    it('leaves a second conversation untouched when the first is throttled', function (): void {
        config(['restaurantline.agent.rate_limit_per_minute' => 2]);

        agentPost('menu/search', ['conversation_id' => 'call-1', 'query' => 'chips'])->assertOk();
        agentPost('menu/search', ['conversation_id' => 'call-1', 'query' => 'chips'])->assertOk();
        agentPost('menu/search', ['conversation_id' => 'call-1', 'query' => 'chips'])->assertStatus(429);

        agentPost('menu/search', ['conversation_id' => 'call-2', 'query' => 'chips'])->assertOk();
    });

    /**
     * A request with no conversation_id — /menu and /hours take none — falls back
     * to the IP. Without that fallback those two routes would share a single
     * empty bucket and throttle each other.
     */
    it('still limits a request that carries no conversation id', function (): void {
        config(['restaurantline.agent.rate_limit_per_minute' => 2]);

        agentGet('hours')->assertOk();
        agentGet('menu')->assertOk();

        agentGet('hours')->assertStatus(429);
    });

    /**
     * The second limit in the pair. One conversation cannot exhaust it, so the
     * global ceiling is exercised by giving every request its own key and
     * sending more than ten times the per-conversation allowance.
     */
    it('caps the whole line even when every conversation is under its own limit', function (): void {
        config(['restaurantline.agent.rate_limit_per_minute' => 1]);

        for ($i = 0; $i < 10; $i++) {
            agentPost('menu/search', ['conversation_id' => 'call-'.$i, 'query' => 'chips'])->assertOk();
        }

        agentPost('menu/search', ['conversation_id' => 'call-fresh', 'query' => 'chips'])
            ->assertStatus(429);
    });
});

describe('the safe 500', function (): void {
    /**
     * Whatever went wrong, the agent gets a sentence it can say and a route out
     * of the call. Anything else — an HTML debug page, an exception message, a
     * file path — is either unsayable or a disclosure, and usually both.
     */
    it('answers an unhandled exception with something the agent can say aloud', function (): void {
        // Debug mode on, because that is the configuration in which Laravel is
        // most eager to hand back internals, and the one a forker is most
        // likely to be running when they first point a real agent at this.
        config(['app.debug' => true]);

        $this->app->bind(Geocoder::class, fn (): Geocoder => new class implements Geocoder
        {
            /**
             * @param  array{lat: float, lon: float}|null  $near
             * @return list<GeocodeCandidate>
             */
            public function geocode(string $query, ?array $near = null, int $limit = 5): array
            {
                throw new RuntimeException('SELECT * FROM secrets; /var/www/html/.env');
            }
        });

        $response = agentPost('address/validate', [
            'conversation_id' => 'call-1',
            'spoken' => '3 Hanbury Street',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error.code', 'server_error')
            ->assertJsonPath(
                'error.say',
                "I'm having trouble with the ordering system. Let me put you through to someone.",
            );

        expect($response->getContent())
            ->not->toContain('RuntimeException')
            ->not->toContain('SELECT * FROM secrets')
            ->not->toContain('/var/www/html');
    });

    /**
     * The renderer sits on every exception, so it has to be careful about which
     * ones it swallows. A validation failure is a deliberate answer with a
     * field-level explanation the agent needs; turning it into "trouble with the
     * ordering system" would hide every bad tool call behind a fake outage.
     */
    it('leaves a validation failure as a 422', function (): void {
        agentPost('menu/search', ['conversation_id' => 'call-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('query');
    });

    /**
     * Likewise the throttler's own 429, which carries a Retry-After the agent
     * can act on.
     */
    it('leaves the throttler its 429', function (): void {
        config(['restaurantline.agent.rate_limit_per_minute' => 1]);

        agentGet('hours')->assertOk();
        agentGet('hours')->assertStatus(429)->assertHeader('Retry-After');
    });

    /**
     * Nothing outside the agent prefix is touched. The renderer is scoped by
     * path, and a forker adding a normal web route should get Laravel's
     * behaviour, debug page and all.
     */
    it('does not reshape errors outside the agent routes', function (): void {
        $this->get('/no-such-page')->assertNotFound()
            ->assertDontSee('server_error');
    });
});
