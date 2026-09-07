<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateAgent;
use App\Http\Middleware\VerifyElevenLabsSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // The agent tools get their own stack rather than riding on the
            // default `api` group: they are stateless, they carry a bearer
            // token that is not Sanctum's, and their rate limit is sized for
            // one phone line rather than for a public API.
            Route::middleware([
                SubstituteBindings::class,
                'throttle:agent',
                AuthenticateAgent::class,
            ])
                ->prefix('api/agent')
                ->group(base_path('routes/agent.php'));

            // Outside the web group on purpose: no session, therefore no CSRF
            // token to fail on, and the signature is the access control.
            Route::middleware([VerifyElevenLabsSignature::class])
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An agent tool endpoint must never answer with a stack trace. The
        // response goes to a language model in the middle of a phone call,
        // which will read whatever it is given some approximation of out loud,
        // and an HTML debug page is both a terrible thing to say to a caller
        // and a generous disclosure of the application's internals.
        //
        // The 500 is kept — a genuine server error should look like one to
        // monitoring — but the body is the same safe JSON shape as everything
        // else on these routes, with a sentence the agent can use.
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/agent/*')) {
                return null;
            }

            // Render callbacks run ahead of Laravel's own handling, so the
            // deliberate responses have to be let through: the 422 a form
            // request throws, and anything already carrying an HTTP status of
            // its own — a 429 from the throttler, a 404 from a bad path. Only
            // a genuine unhandled throwable should reach the body below.
            if ($exception instanceof HttpResponseException
                || $exception instanceof ValidationException
                || $exception instanceof HttpExceptionInterface) {
                return null;
            }

            return response()->json([
                'ok' => false,
                'error' => [
                    'code' => 'server_error',
                    'say' => "I'm having trouble with the ordering system. Let me put you through to someone.",
                ],
            ], 500);
        });
    })->create();
