<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Signs a webhook body the way ElevenLabs is documented to sign it.
 *
 * This exists so the one under-verified part of the integration is cheap to
 * re-check. The wire format — `t=<unix>,v0=<hex hmac sha256>` over
 * `"{timestamp}.{raw body}"` — is corroborated by community references and SDK
 * behaviour rather than spelled out on the documentation page, and has not been
 * confirmed against a real signed request.
 *
 * When a live webhook is available: capture one real header and body, feed them
 * to `VerifyElevenLabsSignature`, and if it fails, the fix is this class and the
 * middleware's `hash_hmac` line. Everything else stays.
 *
 * @see docs/DECISIONS.md #0006
 */
final class FakeElevenLabsSignature
{
    public static function header(string $body, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= now()->getTimestamp();

        return sprintf('t=%d,v0=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$body, $secret));
    }
}
