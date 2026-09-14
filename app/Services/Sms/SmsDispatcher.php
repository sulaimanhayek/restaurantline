<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Enums\SmsKind;
use App\Enums\SmsStatus;
use App\Models\Order;
use App\Models\SmsMessage;

/**
 * Sends a message and writes down that it happened.
 *
 * The pairing is the point. A send with no record is a message nobody can
 * answer questions about later, and every caller of `SmsSender` in this
 * application goes through here so that the record cannot be forgotten.
 *
 * The row is written before the send, as `queued`, and updated after. A worker
 * killed mid-request therefore leaves a queued row rather than nothing at all,
 * which is the difference between "we don't know" and "we never tried".
 */
final readonly class SmsDispatcher
{
    public function __construct(private SmsSender $sender) {}

    /**
     * Text the customer who placed this order.
     *
     * Returns null when there is nobody to text. An order can legitimately have
     * no customer record — one typed into the dashboard by hand, say — and that
     * is not a failure worth recording or retrying.
     */
    public function toOrder(Order $order, SmsKind $kind, string $body): ?SmsMessage
    {
        $number = $order->customer?->phone_number;

        if (! is_string($number) || $number === '') {
            return null;
        }

        $message = SmsMessage::create([
            'restaurant_id' => $order->restaurant_id,
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'to_number' => $number,
            'kind' => $kind,
            'status' => SmsStatus::Queued,
            'body' => $body,
            'provider' => $this->sender->name(),
        ]);

        $result = $this->sender->send($number, $body);

        $message->update($result->sent
            ? [
                'status' => SmsStatus::Sent,
                'provider_message_id' => $result->providerMessageId,
                'sent_at' => now(),
            ]
            : [
                'status' => SmsStatus::Failed,
                'error' => $result->error,
            ]);

        return $message->refresh();
    }
}
