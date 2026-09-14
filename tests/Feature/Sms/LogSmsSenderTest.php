<?php

declare(strict_types=1);

use App\Services\Sms\LogSmsSender;
use Illuminate\Support\Facades\Log;

/**
 * The default SMS driver, and therefore the one an install reaches production
 * with if nobody sets SMS_DRIVER.
 *
 * Two things follow from being the default. On a laptop it has to print the
 * message, because reading the text you would have received is how the
 * confirmation flow gets checked without a Twilio account. In production it
 * must not, because the same line is a customer's phone number filed next to
 * their payment link, in a file that is routinely shipped somewhere else.
 */
it('prints the message on a developer machine, which is the whole point', function (): void {
    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'log driver')
                && $context['to'] === '+447700900123'
                && str_contains($context['body'], 'Ember Grill');
        });

    (new LogSmsSender)->send('+447700900123', 'Ember Grill: your order is confirmed.');
});

it('keeps the number and the body out of the log in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    Log::shouldReceive('info')->never();
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'SMS_DRIVER')
            && ! str_contains($message, '+447700900123'));

    (new LogSmsSender)->send('+447700900123', 'Ember Grill: your order is confirmed.');
});

/**
 * It still reports success in production, deliberately. The order is already
 * placed by the time this runs, and failing it here would roll a completed
 * order back over a misconfiguration the log line above names precisely.
 */
it('still reports a send, so a misconfiguration does not undo a real order', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    Log::spy();

    $result = (new LogSmsSender)->send('+447700900123', 'Ember Grill: your order is confirmed.');

    expect($result->sent)->toBeTrue()
        ->and($result->providerMessageId)->toStartWith('log_');
});
