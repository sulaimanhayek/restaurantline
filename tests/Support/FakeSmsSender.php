<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Sms\SmsResult;
use App\Services\Sms\SmsSender;

/**
 * An SMS sender that keeps what it was handed instead of sending it.
 *
 * The log driver would do for the happy path, but it always succeeds, and half
 * of what is worth testing here is what the application does when a text does
 * not go out. `failing()` gives that side of it, and both sides record the
 * message so a test can assert on the words a customer would have read.
 */
final class FakeSmsSender implements SmsSender
{
    /** @var list<array{to: string, body: string}> */
    public array $messages = [];

    private function __construct(private readonly ?string $error) {}

    public static function working(): self
    {
        return new self(null);
    }

    public static function failing(string $error = 'The carrier rejected the message.'): self
    {
        return new self($error);
    }

    public function send(string $to, string $body): SmsResult
    {
        $this->messages[] = ['to' => $to, 'body' => $body];

        return $this->error === null
            ? SmsResult::sent('fake_'.count($this->messages))
            : SmsResult::failed($this->error);
    }

    public function name(): string
    {
        return 'fake';
    }

    /**
     * The body of the last message, or an empty string if nothing was sent.
     */
    public function lastBody(): string
    {
        $last = end($this->messages);

        return $last === false ? '' : $last['body'];
    }
}
