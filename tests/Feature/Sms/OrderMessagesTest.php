<?php

declare(strict_types=1);

use App\Enums\FulfilmentType;
use App\Enums\PaymentMethod;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Payments\PaymentLink;
use App\Services\Sms\OrderMessages;
use Carbon\CarbonImmutable;

/**
 * The words a customer actually reads.
 *
 * Three rules, and each one below is a test of one of them: the order number
 * comes first because that is what they will be asked for when they ring back;
 * the whole thing stays inside one 160-character segment because a long message
 * is silently billed as two and a restaurant doing three hundred a week
 * notices; and nothing in it should be embarrassing on a lock screen somebody
 * else is looking at.
 */
beforeEach(function (): void {
    $this->restaurant = restaurant(['name' => 'Ember Kitchen', 'timezone' => 'Europe/London']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function messageableOrder(array $attributes = []): Order
{
    /** @var Order $order */
    $order = Order::factory()
        ->for(test()->restaurant)
        ->for(Customer::factory()->for(test()->restaurant)->state(['name' => 'Sam']))
        ->confirmed()
        ->create(array_replace([
            'order_number' => 'L-2356',
            'subtotal' => 2450,
            'total' => 2450,
            'payment_method' => PaymentMethod::Cash,
            'estimated_ready_at' => CarbonImmutable::parse('2026-09-14 18:40:00', 'Europe/London'),
        ], $attributes));

    return $order;
}

it('leads with the order number and the total', function (): void {
    $body = OrderMessages::confirmation(messageableOrder());

    expect($body)->toStartWith('Ember Kitchen: order L-2356 confirmed, £24.50.');
});

/*
 * `spokenWait()` says "in about 20 minutes", which is right for a voice reading
 * it aloud and wrong in a text somebody opens on the bus twenty minutes later.
 * A written message carries a clock time.
 */
it('gives a clock time rather than the phrase the agent speaks', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 18:20:00', 'Europe/London'));

    expect(OrderMessages::confirmation(messageableOrder()))
        ->toContain('Ready around 18:40.')
        ->not->toContain('about 20 minutes');
});

it('names the day when the food is not for today', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 18:20:00', 'Europe/London'));

    $body = OrderMessages::confirmation(messageableOrder([
        'estimated_ready_at' => CarbonImmutable::parse('2026-09-15 12:30:00', 'Europe/London'),
    ]));

    expect($body)->toContain('Ready around 12:30 on Tue 15 Sep.');
});

it('says delivery rather than ready for a delivery order', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 18:20:00', 'Europe/London'));

    $order = messageableOrder([
        'fulfilment_type' => FulfilmentType::Delivery,
        'address_id' => Address::factory()->for(test()->restaurant)->verified(),
        'delivery_fee' => 299,
        'total' => 2749,
    ]);

    expect(OrderMessages::confirmation($order))
        ->toContain('Delivery around 18:40.')
        ->toContain('Please have cash ready for the driver.');
});

describe('how to pay', function (): void {
    it('puts the link in the message when there is one', function (): void {
        $order = messageableOrder(['payment_method' => PaymentMethod::CardLink]);
        $link = new PaymentLink('https://pay.example/abc123', 'cs_test_abc123');

        expect(OrderMessages::confirmation($order, $link))->toContain('Pay here: https://pay.example/abc123');
    });

    /*
     * A card order whose link could not be created has already been moved to
     * cash by the job. Writing "Pay here:" with nothing after it would be worse
     * than saying nothing at all.
     */
    it('promises no link when there is not one', function (): void {
        $order = messageableOrder(['payment_method' => PaymentMethod::CardLink]);

        expect(OrderMessages::confirmation($order))->not->toContain('Pay here');
    });

    it('tells a collection customer to bring cash, not to expect a driver', function (): void {
        expect(OrderMessages::confirmation(messageableOrder()))
            ->toContain('Pay by cash when you collect.')
            ->not->toContain('driver');
    });

    /*
     * The follow-up for the dashboard's "send again" button. Shorter on
     * purpose: the customer already knows what they ordered and is looking for
     * the thing to tap.
     */
    it('sends a short reminder carrying only the link', function (): void {
        $order = messageableOrder(['payment_method' => PaymentMethod::CardLink]);
        $link = new PaymentLink('https://pay.example/abc123', 'cs_test_abc123');

        $body = OrderMessages::paymentLink($order, $link);

        expect($body)->toBe('Ember Kitchen: pay £24.50 for order L-2356 here: https://pay.example/abc123')
            ->and(mb_strlen($body))->toBeLessThanOrEqual(160);
    });
});

/*
 * One SMS segment is 160 GSM-7 characters and a longer message is billed as
 * two or three without saying so. The link is the part most likely to push it
 * over, so the card case is the one measured.
 */
it('fits a confirmation with a payment link into one segment', function (): void {
    $order = messageableOrder(['payment_method' => PaymentMethod::CardLink]);
    $link = new PaymentLink('https://checkout.stripe.com/c/pay/cs_test_a1b2c3d4e5f6g7h8', 'cs_test_a1b2c3');

    expect(mb_strlen(OrderMessages::confirmation($order, $link)))->toBeLessThanOrEqual(160);
});

/*
 * A text sits on a lock screen in front of whoever is nearby. The order number
 * and the total are unavoidable; the address, the customer's name and what they
 * ordered are not, and none of them belong there.
 */
it('puts nothing on a lock screen that should not be there', function (): void {
    $order = messageableOrder([
        'fulfilment_type' => FulfilmentType::Delivery,
        'address_id' => Address::factory()->for(test()->restaurant)->verified(),
    ]);

    $body = OrderMessages::confirmation($order);

    expect($body)->not->toContain('Sam')
        ->and($body)->not->toContain((string) $order->address?->line_1);
});
