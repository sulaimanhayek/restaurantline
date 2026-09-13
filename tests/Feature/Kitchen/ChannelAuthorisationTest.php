<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| Websocket channel authorisation
|--------------------------------------------------------------------------
|
| Every subscription the browser attempts goes through /broadcasting/auth
| first, and the callbacks in routes/channels.php decide it. This is the only
| thing standing between one restaurant's kitchen screen and another
| restaurant's orders — names, phone numbers and delivery addresses — so it is
| tested over HTTP rather than by calling the callbacks directly. A callback
| that is right but wired to the wrong channel name passes the second kind of
| test and fails the first.
|
| The suite runs on the `null` broadcaster, whose auth() is an empty method:
| it would return 200 for anything. So these tests switch to `reverb` — a
| Pusher broadcaster underneath — which is what a real deployment uses. No
| network call is involved: authorising a private channel is an HMAC of the
| socket id against the app secret, computed locally.
|
*/

beforeEach(function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb', [
        'driver' => 'reverb',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'app_id' => 'test-app',
        'options' => [
            'host' => 'localhost',
            'port' => 8080,
            'scheme' => 'http',
            'useTLS' => false,
        ],
        'client_options' => [],
    ]);

    // Channel callbacks belong to a broadcaster instance, not to the
    // application: `Broadcast::channel()` is forwarded to whichever driver is
    // current. The framework registers them once at boot, which — in this
    // suite — was the `null` driver, so the `reverb` driver the line above
    // selects starts out knowing no channels at all and refuses everything.
    // Running the same file again is what the framework itself does at boot;
    // it just has to happen after the connection has changed.
    require base_path('routes/channels.php');

    $this->restaurant = restaurant();
});

/**
 * A subscription attempt, made the way Echo makes one.
 *
 * @return TestResponse<Response>
 */
function authorise(string $channel): TestResponse
{
    /** @var TestResponse<Response> $response */
    $response = postJson('/broadcasting/auth', [
        'channel_name' => $channel,
        'socket_id' => '1234.5678',
    ]);

    return $response;
}

describe('the kitchen channel', function (): void {
    it('refuses a subscriber with nobody signed in behind it', function (): void {
        authorise('private-restaurant.'.$this->restaurant->id.'.kitchen')
            ->assertForbidden();
    });

    it('admits a member of staff at that restaurant', function (): void {
        actingAs(User::factory()->create(['restaurant_id' => $this->restaurant->id]));

        authorise('private-restaurant.'.$this->restaurant->id.'.kitchen')
            ->assertOk()
            ->assertJsonStructure(['auth']);
    });

    it('refuses a signed-in user from another restaurant', function (): void {
        $other = restaurant(['name' => 'Somebody Else']);

        actingAs(User::factory()->create(['restaurant_id' => $other->id]));

        authorise('private-restaurant.'.$this->restaurant->id.'.kitchen')
            ->assertForbidden();
    });

    it('admits the operator, who belongs to no one restaurant', function (): void {
        actingAs(User::factory()->create(['restaurant_id' => null]));

        authorise('private-restaurant.'.$this->restaurant->id.'.kitchen')
            ->assertOk();
    });

    it('refuses a channel named after a restaurant that does not exist', function (): void {
        actingAs(User::factory()->create(['restaurant_id' => $this->restaurant->id]));

        authorise('private-restaurant.'.($this->restaurant->id + 1).'.kitchen')
            ->assertForbidden();
    });
});

describe('the per-user channel', function (): void {
    it('admits a user to their own', function (): void {
        $user = User::factory()->create(['restaurant_id' => $this->restaurant->id]);

        actingAs($user);

        authorise('private-App.Models.User.'.$user->id)->assertOk();
    });

    it('refuses a user somebody else\'s', function (): void {
        $user = User::factory()->create(['restaurant_id' => $this->restaurant->id]);
        $other = User::factory()->create(['restaurant_id' => $this->restaurant->id]);

        actingAs($user);

        authorise('private-App.Models.User.'.$other->id)->assertForbidden();
    });
});
