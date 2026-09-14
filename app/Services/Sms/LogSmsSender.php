<?php

declare(strict_types=1);

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes the message to the log and reports success.
 *
 * The default, and the reason a fresh clone has a working confirmation flow
 * with no Twilio account: `docker compose up`, place an order, and the text
 * that would have gone out is in `storage/logs/laravel.log` — and, more
 * usefully, in the SMS list in the dashboard, with its body, exactly as the
 * customer would have received it.
 *
 * It reports success because the flow under test is the one that happens when a
 * message sends. A default driver that failed would exercise the error path on
 * every order and teach a forker that something is broken.
 */
final class LogSmsSender implements SmsSender
{
    public function send(string $to, string $body): SmsResult
    {
        Log::info('SMS (log driver — nothing was actually sent)', [
            'to' => $to,
            'body' => $body,
        ]);

        // A fake id shaped like a real one, so anything downstream that stores
        // or displays it is exercised rather than skipped.
        return SmsResult::sent('log_'.Str::lower(Str::random(24)));
    }

    public function name(): string
    {
        return 'log';
    }
}
