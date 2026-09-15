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
 *
 * In production it keeps working but stops writing the number and the body.
 * Being the default is what makes that necessary: an install that reaches
 * production before SMS_DRIVER is set would otherwise accumulate a log file of
 * customers' phone numbers next to their payment links, in a file that gets
 * shipped to whatever aggregator the host has wired up, and nobody would notice
 * because the flow appears to work. The message is still on the `sms_messages`
 * row, behind the dashboard's auth, for anyone who needs to read it.
 */
final class LogSmsSender implements SmsSender
{
    public function send(string $to, string $body): SmsResult
    {
        if (app()->isProduction()) {
            Log::warning(
                'SMS_DRIVER is "log" in production, so no text message was sent. The customer is waiting '
                .'for a payment link that is not coming. Set SMS_DRIVER=twilio.',
            );

            return SmsResult::sent('log_'.Str::lower(Str::random(24)));
        }

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
