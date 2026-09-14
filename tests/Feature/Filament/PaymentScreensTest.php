<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SmsKind;
use App\Enums\SmsStatus;
use App\Filament\Pages\RestaurantSettings;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\SmsMessages\Pages\ListSmsMessages;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\Sms\SmsSender;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\FakeSmsSender;

/*
|--------------------------------------------------------------------------
| The payment screens
|--------------------------------------------------------------------------
|
| Two jobs, and they belong to two different people. The owner decides what the
| restaurant accepts, once, on the settings page — and that setting is what
| decides whether the agent ever asks a caller about payment at all. Whoever is
| answering the phone at eight on a Friday deals with the other one: a customer
| who says the link never arrived.
|
| @see docs/DECISIONS.md #0037
|
*/

beforeEach(function (): void {
    $this->restaurant = restaurant();
    $this->sms = FakeSmsSender::working();

    $this->app->instance(SmsSender::class, $this->sms);

    actingAs(User::factory()->create(['restaurant_id' => $this->restaurant->id]));
});

/**
 * An order with a live link on it, and somebody to text it to.
 *
 * @param  array<string, mixed>  $attributes
 */
function linkedOrder(array $attributes = []): Order
{
    /** @var Order $order */
    $order = Order::factory()
        ->for(test()->restaurant)
        ->for(Customer::factory()->for(test()->restaurant)->state(['phone_number' => '+447700900123']))
        ->confirmed()
        ->create(array_replace([
            'payment_method' => PaymentMethod::CardLink,
            'payment_status' => PaymentStatus::LinkSent,
            'payment_link_url' => 'https://checkout.stripe.com/c/pay/cs_test_a1b2c3',
            'payment_reference' => 'cs_test_a1b2c3',
        ], $attributes));

    return $order;
}

describe('what the restaurant accepts', function (): void {
    it('saves the methods and the link expiry', function (): void {
        Livewire::test(RestaurantSettings::class)
            ->fillForm([
                'accepts_card_link' => false,
                'accepts_cash' => true,
                'payment_link_ttl_minutes' => 120,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->restaurant->refresh();

        expect($this->restaurant->accepts_card_link)->toBeFalse()
            ->and($this->restaurant->accepts_cash)->toBeTrue()
            ->and($this->restaurant->payment_link_ttl_minutes)->toBe(120);
    });

    /*
     * The screen is the only place this is decided, so it is the only place
     * that can quietly turn the agent's payment question on. Both toggles on is
     * the one state where a caller gets asked anything at all.
     */
    it('is what decides whether the agent asks the caller', function (): void {
        Livewire::test(RestaurantSettings::class)
            ->fillForm(['accepts_card_link' => true, 'accepts_cash' => true])
            ->call('save');

        expect($this->restaurant->refresh()->offersChoiceOfPaymentMethod())->toBeTrue();

        Livewire::test(RestaurantSettings::class)
            ->fillForm(['accepts_card_link' => false, 'accepts_cash' => true])
            ->call('save');

        expect($this->restaurant->refresh()->offersChoiceOfPaymentMethod())->toBeFalse();
    });

    it('refuses an expiry longer than a day', function (): void {
        Livewire::test(RestaurantSettings::class)
            ->fillForm(['payment_link_ttl_minutes' => 5000])
            ->call('save')
            ->assertHasFormErrors(['payment_link_ttl_minutes']);
    });
});

describe('texting the link again', function (): void {
    it('sends the same link the order already has', function (): void {
        $order = linkedOrder();

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('resendPaymentLink')
            ->assertHasNoActionErrors();

        expect($this->sms->messages)->toHaveCount(1)
            ->and($this->sms->messages[0]['to'])->toBe('+447700900123')
            // The same URL, not a new session: two live links for one order is
            // two ways to pay for the same dinner.
            ->and($this->sms->lastBody())->toContain('https://checkout.stripe.com/c/pay/cs_test_a1b2c3')
            ->and($order->refresh()->payment_reference)->toBe('cs_test_a1b2c3');
    });

    it('writes the resend down as a payment link', function (): void {
        $order = linkedOrder();

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('resendPaymentLink');

        $message = SmsMessage::query()->sole();

        expect($message->kind)->toBe(SmsKind::PaymentLink)
            ->and($message->status)->toBe(SmsStatus::Sent)
            ->and($message->order_id)->toBe($order->id)
            ->and($message->restaurant_id)->toBe($this->restaurant->id);
    });

    it('records that a link has now been put in front of the customer', function (): void {
        $order = linkedOrder(['payment_status' => PaymentStatus::Unpaid]);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('resendPaymentLink');

        expect($order->refresh()->payment_status)->toBe(PaymentStatus::LinkSent);
    });

    /*
     * A failed resend must not report success. The dispatcher records a
     * provider's refusal rather than throwing it, so the only way to get this
     * wrong is to ignore the row it wrote — and a customer told "we've resent
     * it" waits all evening for a text nobody sent.
     */
    it('says so when the provider refuses it', function (): void {
        $this->app->instance(SmsSender::class, FakeSmsSender::failing('The To number is not a valid phone number.'));

        $order = linkedOrder(['payment_status' => PaymentStatus::Unpaid]);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('resendPaymentLink');

        expect(SmsMessage::query()->sole()->status)->toBe(SmsStatus::Failed)
            // Still unpaid and still unsent. The badge on the order is the one
            // thing standing between this and a forgotten payment.
            ->and($order->refresh()->payment_status)->toBe(PaymentStatus::Unpaid);
    });

    it('is not offered on an order that has been paid', function (): void {
        $order = linkedOrder(['payment_status' => PaymentStatus::Paid]);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionHidden('resendPaymentLink');
    });

    it('is not offered on a cash order, which has no link', function (): void {
        $order = linkedOrder([
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::CashOnCollection,
            'payment_link_url' => null,
            'payment_reference' => null,
        ]);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionHidden('resendPaymentLink');
    });
});

describe('the list of texts', function (): void {
    it('shows what was sent and hides most of the number', function (): void {
        $message = SmsMessage::factory()
            ->for($this->restaurant)
            ->create(['to_number' => '+447700900123']);

        Livewire::test(ListSmsMessages::class)
            ->assertCanSeeTableRecords([$message])
            ->assertSee('123')
            ->assertDontSee('+447700900123');
    });

    it('can be narrowed to the ones that failed', function (): void {
        $failed = SmsMessage::factory()->for($this->restaurant)->failed()->create();
        $sent = SmsMessage::factory()->for($this->restaurant)->create();

        Livewire::test(ListSmsMessages::class)
            ->filterTable('status', [SmsStatus::Failed->value])
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$sent]);
    });
});
