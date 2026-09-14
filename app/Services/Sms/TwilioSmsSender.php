<?php

declare(strict_types=1);

namespace App\Services\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Twilio's Programmable Messaging API.
 *
 * Switched on with SMS_DRIVER=twilio. Twilio rather than an abstraction over
 * three providers because a boilerplate that supports everything supports
 * nothing well: this is one file of about a hundred lines, and swapping it for
 * MessageBird means writing the equivalent one, not unpicking a plugin system.
 *
 * The REST call is a form post to /Messages.json with HTTP basic auth. There is
 * an official Twilio PHP SDK; it is not used, for the same reason there is no
 * ElevenLabs SDK here — one endpoint does not earn a dependency, and the
 * request below is the documentation.
 *
 * Errors never escape. A failed text is recorded on the order's SMS row with
 * whatever Twilio said, because the useful audience for "21211: invalid To
 * number" is the person in the dashboard looking at why the customer never got
 * their link, not a queue worker's stack trace.
 */
final class TwilioSmsSender implements SmsSender
{
    private const ENDPOINT = 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json';

    public function send(string $to, string $body): SmsResult
    {
        $sid = (string) config('restaurantline.twilio.account_sid', '');
        $token = (string) config('restaurantline.twilio.auth_token', '');
        $from = (string) config('restaurantline.twilio.from', '');

        if ($sid === '' || $token === '' || $from === '') {
            // Deliberately not an exception. A half-configured install should
            // still take orders; it just cannot text about them, and the row
            // this message lands on will say exactly that.
            Log::warning('Twilio SMS driver selected but TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN or TWILIO_FROM_NUMBER is empty.');

            return SmsResult::failed('Twilio is not configured. Set TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN and TWILIO_FROM_NUMBER.');
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($sid, $token)
                ->timeout((int) config('restaurantline.sms.timeout', 10))
                ->post(sprintf(self::ENDPOINT, $sid), [
                    'To' => $to,
                    'From' => $from,
                    'Body' => $body,
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('SMS request failed to reach Twilio.', ['exception' => $exception->getMessage()]);

            return SmsResult::failed('Could not reach Twilio: '.$exception->getMessage());
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        if ($response->failed()) {
            // Twilio's own message is far more useful than the status code:
            // "The 'To' number is not a valid phone number" versus "400".
            $message = is_string($payload['message'] ?? null)
                ? $payload['message']
                : 'Twilio returned HTTP '.$response->status().'.';

            $code = $payload['code'] ?? null;

            Log::warning('Twilio rejected an SMS.', [
                'status' => $response->status(),
                'code' => $code,
                'message' => $message,
                // The number is not logged. It is on the sms_messages row for
                // anyone who needs it, behind the dashboard's own auth.
            ]);

            return SmsResult::failed(is_scalar($code) ? sprintf('%s (Twilio %s)', $message, $code) : $message);
        }

        $status = is_string($payload['status'] ?? null) ? $payload['status'] : '';

        // Twilio 201s a message it has accepted but not yet sent, and also one
        // it has already given up on. `failed` at this point is usually a bad
        // From number or a suspended account.
        if (in_array($status, ['failed', 'undelivered'], true)) {
            return SmsResult::failed('Twilio reported status '.$status.'.');
        }

        return SmsResult::sent(is_string($payload['sid'] ?? null) ? $payload['sid'] : null);
    }

    public function name(): string
    {
        return 'twilio';
    }
}
