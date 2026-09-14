<?php

declare(strict_types=1);

use App\Http\Controllers\Dashboard\ConversationAudioController;
use App\Http\Controllers\Payments\FakeCheckoutController;
use App\Http\Controllers\Payments\PaymentReturnController;
use App\Livewire\KitchenDisplay;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

/*
 * Pages a customer reaches from a link in a text message.
 *
 * Public, because the person opening them has no account and never will. Both
 * are written on the assumption that the URL is guessable — see the
 * controllers — so neither one shows anything that would matter in the hands of
 * somebody who guessed it.
 */
Route::get('/thanks/{order}', PaymentReturnController::class)->name('payments.return');

/*
 * The fake payment driver's checkout page. The controller refuses to serve it
 * unless PAYMENT_DRIVER=fake and the environment is not production, so these
 * routes exist everywhere and work only where they should.
 */
Route::get('/pay/{reference}', [FakeCheckoutController::class, 'show'])->name('payments.fake');
Route::post('/pay/{reference}', [FakeCheckoutController::class, 'pay'])->name('payments.fake.pay');

/*
 * Dashboard routes that are not Filament pages.
 *
 * They sit behind Filament's own Authenticate middleware rather than a bare
 * `auth` alias so that a request arriving here is held to exactly the same
 * standard as one arriving at a panel page — one place to change when this
 * install grows roles or two-factor.
 */
Route::middleware(['web', Authenticate::class])->group(function (): void {
    Route::get('/conversations/{conversation}/audio', ConversationAudioController::class)
        ->name('conversations.audio');

    /*
     * The kitchen display. Outside the panel because it is a wall-mounted
     * screen rather than a page anybody navigates — see the component.
     *
     * The middleware above covers this request. It does not cover Livewire's
     * own update endpoint, which every tap on the screen goes through, so the
     * component re-checks the same rule on each request in its boot().
     */
    Route::get('/kitchen', KitchenDisplay::class)->name('kitchen');
});
