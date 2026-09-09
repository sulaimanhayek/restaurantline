<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shared secret between the ElevenLabs agent and this application.
 *
 * The tool endpoints sit on the public internet — they have to, the agent
 * platform calls them from its own infrastructure — and they will happily read
 * a restaurant's menu, price an order and create one. This is the only thing
 * standing in front of them.
 *
 * Two deliberate choices:
 *
 * - **An empty `AGENT_API_TOKEN` denies everything.** The tempting alternative,
 *   skipping the check when no token is configured, means a forker who deploys
 *   before finishing their `.env` publishes an open order-creation endpoint and
 *   gets no signal at all that they have.
 *
 * - **`hash_equals`.** A plain `===` leaks the token a character at a time to
 *   anyone patient enough to measure the difference.
 *
 * The 401 body is the same JSON shape as everything else on these routes, so a
 * forker testing with the wrong token in `curl` gets a sentence rather than an
 * HTML error page.
 */
final class AuthenticateAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('restaurantline.agent.token');
        $presented = $request->bearerToken();

        if (! is_string($expected) || $expected === '') {
            Log::error('An agent tool endpoint was called with no AGENT_API_TOKEN configured; rejecting.');

            return $this->deny('This installation has no agent token configured.');
        }

        if (! is_string($presented) || ! hash_equals($expected, $presented)) {
            Log::warning('Agent tool call rejected: bad or missing bearer token.', [
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            return $this->deny('Missing or invalid bearer token.');
        }

        return $next($request);
    }

    private function deny(string $detail): Response
    {
        return response()->json([
            'ok' => false,
            'error' => [
                'code' => 'unauthenticated',
                'say' => "I'm sorry, I can't reach the ordering system right now.",
                'detail' => $detail,
            ],
        ], 401);
    }
}
