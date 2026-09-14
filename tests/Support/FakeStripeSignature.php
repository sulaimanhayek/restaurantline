<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Signs a payload the way Stripe does.
 *
 * The twin of FakeElevenLabsSignature, and the same reasoning: a test that
 * bypassed the signature by stubbing the middleware out would prove the
 * controller works and nothing about the thing actually protecting it.
 *
 * The array of secrets is what makes the rotation case testable — Stripe signs
 * with every active endpoint secret during a rollover, and the verifier has to
 * accept a header carrying several `v1` values.
 */
final class FakeStripeSignature
{
    /**
     * @param  list<string>  $secrets
     */
    public static function header(string $body, array $secrets, ?int $timestamp = null): string
    {
        $timestamp ??= now()->getTimestamp();

        $parts = ['t='.$timestamp];

        foreach ($secrets as $secret) {
            $parts[] = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        }

        return implode(',', $parts);
    }
}
