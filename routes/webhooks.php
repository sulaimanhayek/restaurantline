<?php

declare(strict_types=1);

use App\Http\Controllers\Webhooks\ElevenLabsWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks
|--------------------------------------------------------------------------
|
| Public, unauthenticated by session, and verified by signature. The URL is
| not a secret — it goes in the ElevenLabs dashboard and in the provisioning
| output — so the signature middleware is the whole of the access control and
| runs before anything else touches the request.
|
| CSRF does not apply: these routes are registered outside the web middleware
| group, so there is no session and no token to check.
|
*/

Route::post('/webhooks/elevenlabs', ElevenLabsWebhookController::class)
    ->name('webhooks.elevenlabs');
