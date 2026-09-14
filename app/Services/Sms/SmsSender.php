<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Sending one text message.
 *
 * One method, because that is the entire surface the application needs and
 * every extra method is one more thing a forker swapping in MessageBird or
 * Vonage has to implement. Bind an implementation in AppServiceProvider; the
 * log driver is the default, so `docker compose up` sends nothing, costs
 * nothing and needs no account.
 *
 * Implementations must not throw for a message that could not be sent — return
 * `SmsResult::failed()` instead. The caller is a queued job reacting to a phone
 * call that has already ended, and an exception there buys a retry of something
 * that will fail again, plus a failed_jobs row nobody reads.
 */
interface SmsSender
{
    /**
     * @param  string  $to  E.164, e.g. +447700900123.
     */
    public function send(string $to, string $body): SmsResult;

    /**
     * The name recorded against messages this sender handled.
     *
     * Stored on every `sms_messages` row so that a dashboard showing a month of
     * traffic can say which of them went through a real provider and which were
     * written to a log during development.
     */
    public function name(): string;
}
