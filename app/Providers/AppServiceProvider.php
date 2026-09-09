<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Geocoding\FakeGeocoder;
use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\GoogleGeocoder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Where the outbound integrations are chosen.
 *
 * Every service that leaves the machine sits behind an interface with a fake
 * implementation, and the fake is the default. That is what makes
 * `docker compose up` work with no API keys and what keeps the test suite off
 * the network — a test that can fail because someone else's API is having a bad
 * morning is not a test.
 *
 * Swapping a driver is one environment variable. Adding your own is one class
 * and one `match` arm below.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Geocoder::class, function (): Geocoder {
            $driver = (string) config('restaurantline.geocoder.driver', 'fake');

            return match ($driver) {
                'fake' => new FakeGeocoder,
                'google' => new GoogleGeocoder,
                default => throw new InvalidArgumentException(
                    sprintf('Unknown geocoder driver [%s]. Set GEOCODER_DRIVER to fake or google.', $driver),
                ),
            };
        });
    }

    public function boot(): void
    {
        $this->registerAgentRateLimiter();
    }

    /**
     * Two ceilings on the agent tool endpoints, and they do different jobs.
     *
     * The per-conversation limit is the one that matters day to day. An agent
     * that gets into a loop — searching the menu for the same misheard word
     * over and over — burns tool calls fast, and the limit stops that one call
     * without taking the phone line down for everybody else. Keying on the IP
     * would do the opposite: ElevenLabs calls from shared infrastructure, so
     * every conversation shares an address and one bad call would throttle the
     * lot.
     *
     * The global ceiling is the backstop for the case the first limit cannot
     * see — a caller varying `conversation_id` on every request. The token is
     * the real protection there; this just caps how expensive it can get.
     */
    private function registerAgentRateLimiter(): void
    {
        RateLimiter::for('agent', function (Request $request): array {
            $perMinute = (int) config('restaurantline.agent.rate_limit_per_minute', 120);
            $conversation = $request->input('conversation_id');

            return [
                Limit::perMinute($perMinute)->by(
                    is_string($conversation) && $conversation !== ''
                        ? 'conversation:'.$conversation
                        : 'ip:'.$request->ip(),
                ),
                Limit::perMinute($perMinute * 10)->by('agent-global'),
            ];
        });
    }
}
