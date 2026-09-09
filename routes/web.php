<?php

declare(strict_types=1);

use App\Http\Controllers\Dashboard\ConversationAudioController;
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
});
