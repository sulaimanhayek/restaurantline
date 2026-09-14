<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\SmsKind;
use App\Enums\SmsStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SmsMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One text message this application tried to send.
 *
 * Every attempt gets a row, successful or not, with the body exactly as it went
 * out. Two reasons, and the first one happens weekly: a customer rings back
 * saying they never got their payment link, and somebody needs to answer that
 * from the dashboard in fifteen seconds rather than by grepping a log file on a
 * production box.
 *
 * The second is the invoice. An SMS bug that sends the same text in a loop is
 * invisible until the bill arrives, and a list ordered by time with a count on
 * it makes it obvious the same evening.
 *
 * @property int $id
 * @property int $restaurant_id
 * @property int|null $order_id
 * @property int|null $customer_id
 * @property string $to_number E.164
 * @property SmsKind $kind
 * @property SmsStatus $status
 * @property string $body
 * @property string|null $provider
 * @property string|null $provider_message_id
 * @property string|null $error
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Restaurant $restaurant
 * @property-read Order|null $order
 * @property-read Customer|null $customer
 *
 * @method static SmsMessageFactory factory($count = null, $state = [])
 */
class SmsMessage extends Model
{
    /** @use HasFactory<SmsMessageFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SmsKind::class,
            'status' => SmsStatus::class,
            'sent_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
        ];
    }

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The number with everything but the last three digits hidden.
     *
     * Used on screens that list messages, where the phone number is noise and
     * the last few digits are enough to tell two customers apart. The full
     * number is still one click away on the message itself — this is about not
     * putting a wall of personal data on a screen somebody left logged in.
     */
    public function maskedNumber(): string
    {
        $length = mb_strlen($this->to_number);

        if ($length <= 4) {
            return $this->to_number;
        }

        return str_repeat('•', $length - 3).mb_substr($this->to_number, -3);
    }
}
