<?php

declare(strict_types=1);

use App\Enums\SmsKind;
use App\Enums\SmsStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SmsMessage;
use App\Services\Sms\SmsDispatcher;
use App\Services\Sms\SmsResult;
use App\Services\Sms\SmsSender;
use Tests\Support\FakeSmsSender;

/**
 * Sending a text and writing down that it happened, as one indivisible thing.
 *
 * The pairing is the whole point of this class. A send with no record is a
 * message nobody can answer questions about a week later, and every caller of
 * `SmsSender` in this application goes through here so that the record cannot
 * be forgotten at a call site.
 */
beforeEach(function (): void {
    $this->restaurant = restaurant();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function orderToText(array $attributes = []): Order
{
    /** @var Order $order */
    $order = Order::factory()
        ->for(test()->restaurant)
        ->for(Customer::factory()->for(test()->restaurant)->state(['phone_number' => '+447700900123']))
        ->confirmed()
        ->create($attributes);

    return $order;
}

it('records what was sent, to whom, and by which provider', function (): void {
    $sender = FakeSmsSender::working();

    $message = (new SmsDispatcher($sender))->toOrder(orderToText(), SmsKind::PaymentLink, 'Pay here: https://pay.example/x');

    expect($message)->not->toBeNull()
        ->and($message?->status)->toBe(SmsStatus::Sent)
        ->and($message?->kind)->toBe(SmsKind::PaymentLink)
        ->and($message?->body)->toBe('Pay here: https://pay.example/x')
        ->and($message?->to_number)->toBe('+447700900123')
        ->and($message?->provider)->toBe('fake')
        ->and($message?->provider_message_id)->toBe('fake_1')
        ->and($message?->sent_at)->not->toBeNull()
        ->and($message?->error)->toBeNull()
        ->and($sender->messages)->toHaveCount(1);
});

it('ties the row to the order, the customer and the restaurant', function (): void {
    $order = orderToText();

    $message = (new SmsDispatcher(FakeSmsSender::working()))->toOrder($order, SmsKind::OrderConfirmation, 'Hello');

    expect($message?->order_id)->toBe($order->id)
        ->and($message?->customer_id)->toBe($order->customer_id)
        ->and($message?->restaurant_id)->toBe($order->restaurant_id)
        ->and($order->smsMessages()->count())->toBe(1);
});

/*
 * A failed text is a normal operational event — a mistyped number, a landline,
 * a provider having a bad morning — and the caller has already hung up. What
 * matters is that somebody in the dashboard can see why and press send again.
 */
it('keeps the provider error on the row rather than throwing', function (): void {
    $message = (new SmsDispatcher(FakeSmsSender::failing('21211: not a mobile number')))
        ->toOrder(orderToText(), SmsKind::OrderConfirmation, 'Hello');

    expect($message?->status)->toBe(SmsStatus::Failed)
        ->and($message?->error)->toBe('21211: not a mobile number')
        ->and($message?->sent_at)->toBeNull()
        ->and($message?->provider_message_id)->toBeNull();
});

/*
 * The row is written before the send and updated after, so a worker killed
 * mid-request leaves `queued` rather than nothing at all. That is the
 * difference between "we do not know what happened" and "we never tried",
 * which is the first question anybody asks about a missing text.
 */
it('leaves a queued row behind when the process dies mid-send', function (): void {
    $sender = new class implements SmsSender
    {
        public function send(string $to, string $body): SmsResult
        {
            throw new RuntimeException('The worker was killed.');
        }

        public function name(): string
        {
            return 'exploding';
        }
    };

    expect(fn () => (new SmsDispatcher($sender))->toOrder(orderToText(), SmsKind::OrderConfirmation, 'Hello'))
        ->toThrow(RuntimeException::class);

    $message = SmsMessage::query()->sole();

    expect($message->status)->toBe(SmsStatus::Queued)
        ->and($message->sent_at)->toBeNull();
});

/*
 * An order typed into the dashboard by hand has no customer to text. Not a
 * failure, and not worth a row saying one happened.
 */
it('does nothing for an order with nobody to text', function (): void {
    $order = orderToText();
    $order->update(['customer_id' => null]);

    $message = (new SmsDispatcher(FakeSmsSender::working()))->toOrder($order->refresh(), SmsKind::OrderConfirmation, 'Hello');

    expect($message)->toBeNull()
        ->and(SmsMessage::query()->count())->toBe(0);
});

/*
 * The dashboard shows the number, because somebody chasing a missing text
 * needs it. Anywhere it might be read by someone who has no business with it,
 * the last three digits are enough to confirm the right number was used.
 */
it('can mask the number for anywhere it should not be read in full', function (): void {
    $message = (new SmsDispatcher(FakeSmsSender::working()))->toOrder(orderToText(), SmsKind::OrderConfirmation, 'Hello');

    expect($message?->maskedNumber())
        ->toEndWith('123')
        ->not->toContain('7700900');
});
