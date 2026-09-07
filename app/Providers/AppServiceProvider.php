<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Geocoding\FakeGeocoder;
use App\Services\Geocoding\Geocoder;
use App\Services\Geocoding\GoogleGeocoder;
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
        //
    }
}
