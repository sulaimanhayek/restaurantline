<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SmsKind;
use App\Models\Order;
use App\Services\Payments\PaymentLink;
use App\Services\Payments\PaymentLinkException;
use App\Services\Payments\PaymentLinkProvider;
use App\Services\Sms\OrderMessages;
use App\Services\Sms\SmsDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Creates the payment link and texts the customer, after the call has ended.
 *
 * Queued rather than done inline in the confirm endpoint, because the caller is
 * still on the phone waiting for the agent to say "that's confirmed" and Stripe
 * and Twilio are two network round trips they should not have to sit through.
 * The order is already confirmed in the database before this runs; nothing here
 * changes whether the kitchen cooks it.
 *
 * That is also why it is allowed to fail. A link that could not be created and
 * a text that would not send both leave a real, confirmed order that the
 * kitchen will make and somebody will pay for at the door. Loud in the
 * dashboard, invisible to dinner.
 *
 * @see docs/DECISIONS.md #0037, #0038
 */
final class SendOrderConfirmation implements ShouldQueue
{
    use Queueable;

    /**
     * Three attempts over a couple of minutes. Stripe having a bad thirty
     * seconds is the failure this is for; anything still failing after two
     * minutes is a configuration problem that retrying will not fix, and the
     * customer has been waiting long enough by then that a cash order is the
     * better outcome.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $orderId) {}

    public function handle(PaymentLinkProvider $payments, SmsDispatcher $sms): void
    {
        $order = Order::query()->with(['restaurant', 'customer'])->find($this->orderId);

        if (! $order instanceof Order) {
            // Deleted between the call ending and the queue getting to it.
            // Nothing to do and nothing wrong.
            return;
        }

        $link = null;

        if ($order->payment_method === PaymentMethod::CardLink) {
            $link = $this->createLink($payments, $order);
        }

        $message = $sms->toOrder($order, SmsKind::OrderConfirmation, OrderMessages::confirmation($order, $link));

        // `link_sent` means the text carrying the link actually went out, which
        // is the only version of that claim worth storing: a customer chasing a
        // link they never received is asking about the message, not about
        // whether Stripe returned a URL.
        if ($link !== null && $message !== null && $message->status->wasSent()) {
            $order->update(['payment_status' => PaymentStatus::LinkSent]);
        }
    }

    /**
     * Create the link, or fall back to cash once the retries are spent.
     *
     * The fallback is the interesting part. Leaving the order unpaid with no
     * link is the one outcome nobody can act on — the customer has nothing to
     * tap and the driver has not been told to collect. Cash is a worse margin
     * and a completed order.
     */
    private function createLink(PaymentLinkProvider $payments, Order $order): ?PaymentLink
    {
        try {
            $link = $payments->createLink($order);
        } catch (PaymentLinkException $exception) {
            if ($this->attempts() < $this->tries) {
                // Let the queue retry. Nothing has been written yet, so a second
                // attempt starts from the same place this one did.
                throw $exception;
            }

            Log::error('Could not create a payment link; falling back to cash.', [
                'order_number' => $order->order_number,
                'exception' => $exception->getMessage(),
            ]);

            $order->update([
                'payment_method' => PaymentMethod::Cash,
                'payment_status' => PaymentStatus::CashOnCollection,
            ]);

            return null;
        }

        $order->update([
            'payment_link_url' => $link->url,
            'payment_reference' => $link->reference,
            'payment_link_expires_at' => $link->expiresAt,
        ]);

        return $link;
    }
}
