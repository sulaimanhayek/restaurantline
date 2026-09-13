<?php

declare(strict_types=1);

use App\Http\Controllers\Dashboard\ConversationAudioController;
use App\Livewire\KitchenDisplay;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

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
