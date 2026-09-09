<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Pages\RestaurantSettings;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->restaurant = restaurant();

    actingAs(User::factory()->create(['restaurant_id' => $this->restaurant->id]));
});

/*
|--------------------------------------------------------------------------
| Orders
|--------------------------------------------------------------------------
|
| What the dashboard may change about an order is narrow on purpose: where it
| has got to, whether the money arrived, and what the kitchen needs told. The
| items, prices and address were agreed with a caller on a recorded line.
|
*/
describe('orders', function (): void {
    it('moves an order along', function (): void {
        $order = Order::factory()->for($this->restaurant)->create(['status' => OrderStatus::Confirmed]);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->fillForm(['status' => OrderStatus::Preparing->value])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($order->fresh()->status)->toBe(OrderStatus::Preparing);
    });

    it('marks an order paid', function (): void {
        $order = Order::factory()->for($this->restaurant)->create(['payment_status' => PaymentStatus::Unpaid]);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->fillForm(['payment_status' => PaymentStatus::Paid->value])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
    });

    it('will not cancel an order without a reason', function (): void {
        $order = Order::factory()->for($this->restaurant)->create(['status' => OrderStatus::Confirmed]);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->fillForm(['status' => OrderStatus::Cancelled->value, 'cancellation_reason' => ''])
            ->call('save')
            ->assertHasFormErrors(['cancellation_reason']);

        expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    });

    it('cancels with a reason', function (): void {
        $order = Order::factory()->for($this->restaurant)->create(['status' => OrderStatus::Confirmed]);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->fillForm([
                'status' => OrderStatus::Cancelled->value,
                'cancellation_reason' => 'Kitchen out of chicken.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $order->refresh();

        expect($order->status)->toBe(OrderStatus::Cancelled)
            ->and($order->cancellation_reason)->toBe('Kitchen out of chicken.');
    });

    it('does not offer a way to add an order by hand', function (): void {
        Livewire::test(ListOrders::class)->assertActionDoesNotExist('create');
    });
});

/*
|--------------------------------------------------------------------------
| Restaurant settings
|--------------------------------------------------------------------------
*/
describe('restaurant settings', function (): void {
    it('saves the details the agent reads out', function (): void {
        Livewire::test(RestaurantSettings::class)
            ->fillForm([
                'name' => 'Ember & Grill',
                'agent_greeting' => 'Ember and Grill, how can I help?',
                'is_accepting_orders' => false,
            ])
            ->call('save')
            ->assertHasNoErrors();

        $restaurant = Restaurant::current();

        expect($restaurant->name)->toBe('Ember & Grill')
            ->and($restaurant->agent_greeting)->toBe('Ember and Grill, how can I help?')
            ->and($restaurant->is_accepting_orders)->toBeFalse();
    });

    it('is the one switch that stops the phone taking orders', function (): void {
        $this->restaurant->update(['is_accepting_orders' => true]);

        Livewire::test(RestaurantSettings::class)
            ->assertFormSet(['is_accepting_orders' => true]);
    });
});
