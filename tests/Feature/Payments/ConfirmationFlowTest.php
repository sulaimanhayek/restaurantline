<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SmsKind;
use App\Enums\SmsStatus;
use App\Jobs\SendOrderConfirmation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SmsMessage;
use App\Services\Payments\PaymentLinkException;
use App\Services\Payments\PaymentLinkProvider;
use App\Services\Sms\SmsSender;
use Tests\Support\DemoMenu;
use Tests\Support\FakeSmsSender;
use Tests\Support\UnavailablePaymentLinkProvider;

/**
 * What happens in the seconds after the caller hangs up.
 *
 * One job does all of it: create the payment link if the order needs one, text
 * the customer, and write down that it happened. It runs on the queue precisely
 * because none of it is allowed to affect whether the kitchen cooks the food —
 * so most of what is worth asserting here is that the failures stay contained.
 *
 * @see docs/DECISIONS.md #0038
 */
beforeEach(function (): void {
    $this->restaurant = alwaysOpen(restaurant());
    $this->sms = FakeSmsSender::working();

    $this->app->instance(SmsSender::class, $this->sms);
});

/**
 * A confirmed order belonging to the restaurant under test.
 *
 * Built with the factory rather than driven through the agent endpoints,
 * because everything below is about the job: the order arriving already
 * confirmed is the job's precondition, not something it should have to re-earn.
 *
 * @param  array<string, mixed>  $attributes
 */
function confirmedOrder(array $attributes = []): Order
{
    /** @var Order $order */
    $order = Order::factory()
        ->for(test()->restaurant)
        ->for(Customer::factory()->for(test()->restaurant)->state(['phone_number' => '+447700900123']))
        ->confirmed()
        ->create(array_replace([
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::CashOnCollection,
        ], $attributes));

    return $order;
}

/**
 * Run the job the way the queue would.
 */
function runConfirmation(Order $order, int $tries = 3): void
{
    $job = new SendOrderConfirmation($order->id);
    $job->tries = $tries;

    dispatch_sync($job);
}

describe('a cash order', function (): void {
    it('texts the customer and writes the message down', function (): void {
        $order = confirmedOrder();

        runConfirmation($order);

        $message = SmsMessage::query()->sole();

        expect($message->status)->toBe(SmsStatus::Sent)
            ->and($message->kind)->toBe(SmsKind::OrderConfirmation)
            ->and($message->to_number)->toBe('+447700900123')
            ->and($message->provider)->toBe('fake')
            ->and($message->provider_message_id)->not->toBeNull()
            ->and($message->sent_at)->not->toBeNull()
            ->and($message->order_id)->toBe($order->id)
            ->and($message->restaurant_id)->toBe($this->restaurant->id);
    });

    it('says the order number, the total and how to pay', function (): void {
        $order = confirmedOrder();

        runConfirmation($order);

        expect($this->sms->lastBody())
            ->toContain($order->order_number)
            ->toContain($order->totalMoney()->format())
            ->toContain('Pay by cash when you collect.');
    });

    it('asks for no payment link at all', function (): void {
        $provider = new UnavailablePaymentLinkProvider;
        $this->app->instance(PaymentLinkProvider::class, $provider);

        runConfirmation(confirmedOrder());

        expect($provider->calls)->toBe(0);
    });
});

describe('a card order', function (): void {
    it('creates a link, texts it, and records that the link went out', function (): void {
        $order = confirmedOrder([
            'payment_method' => PaymentMethod::CardLink,
            'payment_status' => PaymentStatus::Unpaid,
        ]);

        runConfirmation($order);

        $order->refresh();

        expect($order->payment_status)->toBe(PaymentStatus::LinkSent)
            ->and($order->payment_link_url)->toStartWith(url('/pay/'))
            ->and($order->payment_reference)->toStartWith('fake_')
            ->and($order->payment_link_expires_at)->not->toBeNull()
            ->and($this->sms->lastBody())->toContain((string) $order->payment_link_url);
    });

    /*
     * `link_sent` is a claim about the text, not about Stripe. A customer
     * chasing a link they never received is asking about the message, and an
     * order that says the link was sent when the text bounced sends whoever
     * picks it up looking in the wrong place.
     */
    it('does not claim the link was sent when the text failed', function (): void {
        $this->app->instance(SmsSender::class, FakeSmsSender::failing());

        $order = confirmedOrder([
            'payment_method' => PaymentMethod::CardLink,
            'payment_status' => PaymentStatus::Unpaid,
        ]);

        runConfirmation($order);

        expect($order->refresh()->payment_status)->toBe(PaymentStatus::Unpaid)
            ->and($order->payment_link_url)->not->toBeNull();

        $message = SmsMessage::query()->sole();

        expect($message->status)->toBe(SmsStatus::Failed)
            ->and($message->error)->toBe('The carrier rejected the message.')
            ->and($message->sent_at)->toBeNull();
    });

    /*
     * Stripe having a bad thirty seconds is worth a retry, and nothing has been
     * written when the link fails, so a second attempt starts where this one
     * did.
     */
    it('lets the queue retry while it still has attempts left', function (): void {
        $this->app->instance(PaymentLinkProvider::class, new UnavailablePaymentLinkProvider);

        $order = confirmedOrder([
            'payment_method' => PaymentMethod::CardLink,
            'payment_status' => PaymentStatus::Unpaid,
        ]);

        expect(fn () => runConfirmation($order))->toThrow(PaymentLinkException::class);

        expect(SmsMessage::query()->count())->toBe(0);
    });

    /*
     * The outcome that matters. An order left unpaid with no link is the one
     * nobody can act on — the customer has nothing to tap and the driver has
     * not been told to collect. Cash is a worse margin and a completed order.
     */
    it('falls back to cash once the attempts are spent, and says so in the text', function (): void {
        $this->app->instance(PaymentLinkProvider::class, new UnavailablePaymentLinkProvider);

        $order = confirmedOrder([
            'payment_method' => PaymentMethod::CardLink,
            'payment_status' => PaymentStatus::Unpaid,
        ]);

        runConfirmation($order, tries: 1);

        $order->refresh();

        expect($order->payment_method)->toBe(PaymentMethod::Cash)
            ->and($order->payment_status)->toBe(PaymentStatus::CashOnCollection)
            ->and($order->payment_link_url)->toBeNull()
            ->and($this->sms->lastBody())->toContain('Pay by cash when you collect.')
            ->and(SmsMessage::query()->sole()->status)->toBe(SmsStatus::Sent);
    });
});

describe('nothing to text', function (): void {
    it('does nothing at all for an order with no customer', function (): void {
        $order = confirmedOrder();
        $order->update(['customer_id' => null]);

        runConfirmation($order);

        expect(SmsMessage::query()->count())->toBe(0)
            ->and($this->sms->messages)->toBeEmpty();
    });

    /*
     * The call ended, the order was cancelled in the dashboard and deleted
     * before the worker got to it. Nothing to do, and nothing wrong.
     */
    it('shrugs at an order that is no longer there', function (): void {
        $order = confirmedOrder();
        $id = $order->id;
        $order->delete();

        dispatch_sync(new SendOrderConfirmation($id));

        expect(SmsMessage::query()->count())->toBe(0);
    });
});

/*
 * The end-to-end version of everything above: the agent confirms, the caller
 * hears "that's confirmed", and the text goes out afterwards. `afterResponse`
 * rather than a plain dispatch, so this is the path an install running
 * QUEUE_CONNECTION=sync actually takes.
 */
it('sends the confirmation after the agent has already answered', function (): void {
    $this->menu = new DemoMenu($this->restaurant);

    $number = (string) agentPost('orders', orderPayload())->assertOk()->json('order_number');

    agentPost("orders/{$number}/confirm", [
        'conversation_id' => 'call-1',
        'payment_method' => 'cash',
    ])->assertOk();

    $message = SmsMessage::query()->sole();

    expect($message->status)->toBe(SmsStatus::Sent)
        ->and($message->body)->toContain($number)
        ->and($this->sms->messages)->toHaveCount(1);
});
