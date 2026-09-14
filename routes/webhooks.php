<?php

declare(strict_types=1);

use App\Http\Controllers\Webhooks\ElevenLabsWebhookController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Http\Middleware\VerifyElevenLabsSignature;
use App\Http\Middleware\VerifyStripeSignature;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks
|--------------------------------------------------------------------------
|
| Public, unauthenticated by session, and verified by signature. The URLs are
| not secrets — they go in the ElevenLabs and Stripe dashboards, and in the
| provisioning output — so the signature middleware is the whole of the access
| control and runs before anything else touches the request.
|
| Each route declares its own verifier. The two senders sign with different
| secrets, so there is no group-wide middleware that could check both.
|
| CSRF does not apply: these routes are registered outside the web middleware
| group, so there is no session and no token to check.
|
*/

Route::post('/webhooks/elevenlabs', ElevenLabsWebhookController::class)
    ->middleware(VerifyElevenLabsSignature::class)
    ->name('webhooks.elevenlabs');

/*
 * Payments. Only reached when PAYMENT_DRIVER=stripe; the route exists either
 * way so that switching the driver on is one environment variable and one
 * endpoint pasted into the Stripe dashboard, with nothing to deploy.
 *
 * This is the only route in the application that can mark an order paid.
 */
Route::post('/webhooks/stripe', StripeWebhookController::class)
    ->middleware(VerifyStripeSignature::class)
    ->name('webhooks.stripe');
