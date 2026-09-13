<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| Authorisation for every websocket channel. A callback returning false — or a
| request with nobody signed in behind it — is refused with a 403 by
| /broadcasting/auth, and the browser never receives the subscription.
|
| Channel parameters arrive as strings, whatever the channel name looks like:
| Laravel pulls them out of the name with a regular expression and only
| converts them when the parameter is type-hinted as a model. Hence the casts.
|
*/

/**
 * The kitchen display for one restaurant.
 *
 * Private rather than public, and checked against the user's own restaurant
 * rather than merely against being signed in. Orders carry the caller's name,
 * phone number and delivery address, and a channel named after a small integer
 * is trivially guessable, so the guess has to be worth nothing.
 *
 * A null `restaurant_id` is this install's deploying developer, who sees
 * everything — the same rule User::canAccessPanel() applies to the dashboard.
 */
Broadcast::channel('restaurant.{restaurantId}.kitchen', function (User $user, string $restaurantId): bool {
    return $user->restaurant_id === null || $user->restaurant_id === (int) $restaurantId;
});

/**
 * Laravel's per-user channel, used by broadcast notifications.
 *
 * Nothing here sends one yet. It stays wired up because `$user->notify(...)`
 * over the `broadcast` channel is an obvious next thing to add, and finding
 * out that it silently does nothing is a bad afternoon.
 */
Broadcast::channel('App.Models.User.{id}', function (User $user, string $id): bool {
    return $user->id === (int) $id;
});
