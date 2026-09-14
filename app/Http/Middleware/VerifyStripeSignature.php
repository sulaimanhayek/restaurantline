<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proves a payment webhook actually came from Stripe.
 *
 * The same job as VerifyElevenLabsSignature and, as it happens, very nearly the
 * same wire format — but this one guards the endpoint that marks an order paid.
 * An unverified version of this route is a URL anybody can post to in order to
 * get free food, which is a materially more attractive target than a fake
 * transcript.
 *
 * Header: `Stripe-Signature`, formatted `t=<unix>,v1=<hex hmac sha256>`, with
 * possibly several `v1` values during a secret rotation. The signed payload is
 * `"{timestamp}.{raw body}"`.
 *
 * Deliberately not `stripe/stripe-php`'s Webhook::constructEvent. The check is
 * thirty lines, this repo already contains its twin, and a forker reading two
 * near-identical middlewares learns the pattern in a way that a call into an
 * SDK does not teach.
 *
 * @see docs/DECISIONS.md #0039
 */
final class VerifyStripeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('restaurantline.payments.stripe.webhook_secret');

        // Fail closed. A fresh install has no secret, and an install that has
        // no secret must not be accepting payment notifications from strangers.
        if (! is_string($secret) || $secret === '') {
            Log::warning('A Stripe webhook arrived but STRIPE_WEBHOOK_SECRET is not set; rejecting.');

            return $this->reject('not_configured');
        }

        $header = $request->header('Stripe-Signature');

        if (! is_string($header) || $header === '') {
            return $this->reject('missing_signature');
        }

        $parsed = $this->parse($header);

        if ($parsed === null) {
            return $this->reject('malformed_signature');
        }

        [$timestamp, $signatures] = $parsed;

        $tolerance = (int) config('restaurantline.payments.stripe.webhook_tolerance', 300);

        if (abs(now()->getTimestamp() - $timestamp) > $tolerance) {
            return $this->reject('stale_timestamp');
        }

        // Raw body, for the same reason as the other one: re-encoding the JSON
        // would hash a different string than the one Stripe signed.
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return $next($request);
            }
        }

        return $this->reject('signature_mismatch');
    }

    /**
     * `t=1699999999,v1=abc…,v1=def…` → `[1699999999, ['abc…', 'def…']]`.
     *
     * Several v1 values is the normal state of affairs during a secret
     * rotation, when Stripe signs with both the old and the new endpoint
     * secret. A parser that reads only the first would reject half the traffic
     * for the length of the rollover.
     *
     * @return array{int, list<string>}|null
     */
    private function parse(string $header): ?array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            }

            if ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return $timestamp !== null && $signatures !== []
            ? [$timestamp, $signatures]
            : null;
    }

    /**
     * A 401 and nothing else. The reason is logged, never returned.
     */
    private function reject(string $reason): Response
    {
        Log::warning('Rejected a Stripe webhook.', ['reason' => $reason]);

        return response()->json(['ok' => false], 401);
    }
}
