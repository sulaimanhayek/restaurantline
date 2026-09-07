<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proves a post-call webhook actually came from ElevenLabs.
 *
 * This endpoint is public, unauthenticated by any session, and writes call
 * transcripts, recordings and outcomes into the database. Without a signature
 * check anybody who learns the URL can post a transcript claiming a caller
 * ordered something, flag conversations for review, or bury a real call under
 * fabricated ones. The URL is not a secret — it is in the ElevenLabs dashboard,
 * in the provisioning output, and in whatever ngrok logs the forker left open.
 *
 * So the signature is the only thing standing between the URL and the database,
 * and it runs before anything else touches the request.
 *
 * Header: `ElevenLabs-Signature`, formatted `t=<unix>,v0=<hex hmac sha256>`.
 * The signed payload is `"{timestamp}.{raw body}"`.
 *
 * @see docs/DECISIONS.md #0006 — including the honest caveat that this wire
 *      format is corroborated rather than confirmed against a live request.
 * @see docs/DECISIONS.md #0024
 */
final class VerifyElevenLabsSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('restaurantline.elevenlabs.webhook_secret');

        // An empty secret must fail closed. Treating "not configured" as
        // "nothing to check" would leave a fresh install — the state every
        // forker starts in — accepting anything the internet sends it.
        if (! is_string($secret) || $secret === '') {
            Log::warning('A post-call webhook arrived but ELEVENLABS_WEBHOOK_SECRET is not set; rejecting.');

            return $this->reject('not_configured');
        }

        $header = $request->header('ElevenLabs-Signature');

        if (! is_string($header) || $header === '') {
            return $this->reject('missing_signature');
        }

        $parsed = $this->parse($header);

        if ($parsed === null) {
            return $this->reject('malformed_signature');
        }

        [$timestamp, $signature] = $parsed;

        // The timestamp check is what stops a signature captured once from
        // being replayed forever. It is inside the signed payload, so it cannot
        // be edited without invalidating the hash.
        $tolerance = (int) config('restaurantline.elevenlabs.webhook_tolerance', 1800);

        if (abs(now()->getTimestamp() - $timestamp) > $tolerance) {
            return $this->reject('stale_timestamp');
        }

        // getContent(), not the parsed input. Re-encoding the JSON would
        // reorder keys, change spacing and normalise unicode escapes, and the
        // hash would then be over a different string than the one signed.
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            return $this->reject('signature_mismatch');
        }

        return $next($request);
    }

    /**
     * `t=1699999999,v0=abc123…` → `[1699999999, 'abc123…']`.
     *
     * Order is not assumed. The two elements are documented in this order, but
     * a parser that depends on that is one field reordering away from rejecting
     * every genuine webhook.
     *
     * @return array{int, string}|null
     */
    private function parse(string $header): ?array
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            match ($key) {
                't' => $timestamp = ctype_digit($value) ? (int) $value : null,
                'v0' => $signature = $value,
                default => null,
            };
        }

        return $timestamp !== null && $signature !== null && $signature !== ''
            ? [$timestamp, $signature]
            : null;
    }

    /**
     * A 401 and nothing else.
     *
     * The reason is logged, never returned. Telling an unauthenticated caller
     * whether their timestamp was stale or their hash was wrong hands them a
     * way to work out which half they got wrong.
     */
    private function reject(string $reason): Response
    {
        Log::warning('Rejected a post-call webhook.', ['reason' => $reason]);

        return response()->json(['ok' => false], 401);
    }
}
