<?php

declare(strict_types=1);

use App\Http\Controllers\Agent\AvailabilityController;
use App\Http\Controllers\Agent\ConfirmOrderController;
use App\Http\Controllers\Agent\CreateOrderController;
use App\Http\Controllers\Agent\EscalateController;
use App\Http\Controllers\Agent\HoursController;
use App\Http\Controllers\Agent\QuoteController;
use App\Http\Controllers\Agent\SearchMenuController;
use App\Http\Controllers\Agent\ShowMenuController;
use App\Http\Controllers\Agent\ValidateAddressController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Agent tool endpoints
|--------------------------------------------------------------------------
|
| The nine things the ElevenLabs agent can do to this application, mounted at
| /api/agent and behind a bearer token. The prefix, the token check and the
| rate limiter are applied in bootstrap/app.php rather than here, so this file
| is only ever a list of what exists.
|
| `kitchenline:provision` generates the ElevenLabs tool definitions from this
| list, which is why the route names matter: change one here and the agent's
| tools change with it on the next provision.
|
| Everything answers in the same JSON shape — `ok: true` with data, or
| `ok: false` with a code and a sentence to say. See App\Http\Responses\
| AgentResponse and docs/DECISIONS.md #0016–#0018.
|
*/

// Menu
Route::post('menu/search', SearchMenuController::class)->name('agent.menu.search');
Route::get('menu', ShowMenuController::class)->name('agent.menu.show');

// Can we take this order, and when would it be ready?
Route::post('availability', AvailabilityController::class)->name('agent.availability');
Route::get('hours', HoursController::class)->name('agent.hours');

// Delivery
Route::post('address/validate', ValidateAddressController::class)->name('agent.address.validate');

// The order itself. Quote reads it back, orders creates it in `confirming`,
// confirm is the caller's yes. That order of operations is a constraint, not a
// convention — see the README.
Route::post('quote', QuoteController::class)->name('agent.quote');
Route::post('orders', CreateOrderController::class)->name('agent.orders.store');
Route::post('orders/{order}/confirm', ConfirmOrderController::class)->name('agent.orders.confirm');

// Always offer a human.
Route::post('escalate', EscalateController::class)->name('agent.escalate');
