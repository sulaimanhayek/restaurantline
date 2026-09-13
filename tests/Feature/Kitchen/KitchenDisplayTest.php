<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Livewire\KitchenDisplay;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| The kitchen display
|--------------------------------------------------------------------------
|
| A screen with three columns on it, which the kitchen reads at a distance and
| taps with the side of a hand. The things worth testing are the ones that
| would be discovered at the worst possible moment: an order that never
| appears, another restaurant's order that does, and a tap that moves the
| wrong card.
|
*/

beforeEach(function (): void {
    $this->restaurant = restaurant();

    actingAs(User::factory()->create(['restaurant_id' => $this->restaurant->id]));
});

/**
 * A live order on the board, with something on it to cook.
 *
 * @param  array<string, mixed>  $attributes
 */
function ticket(OrderStatus $status = OrderStatus::Confirmed, array $attributes = []): Order
{
    /** @var Order $order */
    $order = Order::factory()
        ->for(restaurant())
        ->withStatus($status)
        ->create($attributes);

    OrderItem::factory()->for($order)->create(['name' => 'Ember Chicken Burger', 'quantity' => 2]);

    return $order->refresh();
}

it('sends a guest to sign in', function (): void {
    auth()->logout();

    get('/kitchen')->assertRedirect('/admin/login');
});

it('renders for a member of staff', function (): void {
    $order = ticket();

    get('/kitchen')
        ->assertOk()
        ->assertSee($this->restaurant->name)
        ->assertSee($order->order_number)
        ->assertSee('Ember Chicken Burger');
});

describe('what reaches the board', function (): void {
    it('shows every status the kitchen is responsible for', function (OrderStatus $status): void {
        $order = ticket($status);

        Livewire::test(KitchenDisplay::class)->assertSee($order->order_number);
    })->with([
        'confirmed' => OrderStatus::Confirmed,
        'accepted' => OrderStatus::Accepted,
        'preparing' => OrderStatus::Preparing,
        'ready' => OrderStatus::Ready,
    ]);

    it('leaves out an order the caller has not agreed to yet, and one already gone', function (OrderStatus $status): void {
        $order = ticket($status);

        Livewire::test(KitchenDisplay::class)->assertDontSee($order->order_number);
    })->with([
        'draft' => OrderStatus::Draft,
        'confirming' => OrderStatus::Confirming,
        'completed' => OrderStatus::Completed,
        'cancelled' => OrderStatus::Cancelled,
        'failed' => OrderStatus::Failed,
    ]);

    it('leaves out another restaurant\'s order', function (): void {
        $other = restaurant(['name' => 'Somebody Else']);

        $mine = ticket();
        $theirs = Order::factory()->for($other)->withStatus(OrderStatus::Confirmed)->create();

        Livewire::test(KitchenDisplay::class)
            ->assertSee($mine->order_number)
            ->assertDontSee($theirs->order_number);
    });

    it('puts the oldest order first', function (): void {
        $second = ticket(OrderStatus::Confirmed, ['confirmed_at' => now()->subMinutes(5)]);
        $first = ticket(OrderStatus::Confirmed, ['confirmed_at' => now()->subMinutes(30)]);

        Livewire::test(KitchenDisplay::class)
            ->assertSeeInOrder([$first->order_number, $second->order_number]);
    });

    /*
     * Asserted against the whole page rather than through Livewire's own test
     * helper, which strips the very attribute this is about. The listener
     * names are what Echo matches an incoming websocket message against: get
     * the format wrong and nothing errors anywhere, the screen simply stops
     * updating until somebody notices the orders are late.
     */
    it('subscribes to its own restaurant\'s channel', function (): void {
        $channel = 'echo-private:restaurant.'.$this->restaurant->id.'.kitchen';

        get('/kitchen')
            ->assertSee($channel.',.order.received', escape: false)
            ->assertSee($channel.',.order.status-changed', escape: false);
    });
});

describe('moving a card along', function (): void {
    it('walks an order from confirmed to done, one tap at a time', function (): void {
        $order = ticket(OrderStatus::Confirmed);

        $display = Livewire::test(KitchenDisplay::class);

        foreach ([OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Completed] as $expected) {
            $display->call('advance', $order->id);

            expect($order->refresh()->status)->toBe($expected);
        }
    });

    it('treats an accepted order the same as a confirmed one', function (): void {
        $order = ticket(OrderStatus::Accepted);

        Livewire::test(KitchenDisplay::class)->call('advance', $order->id);

        expect($order->refresh()->status)->toBe(OrderStatus::Preparing);
    });

    it('takes a completed order off the board', function (): void {
        $order = ticket(OrderStatus::Ready);

        Livewire::test(KitchenDisplay::class)
            ->assertSee($order->order_number)
            ->call('advance', $order->id)
            ->assertDontSee($order->order_number);
    });

    it('stamps the time each status was reached', function (): void {
        $order = ticket(OrderStatus::Confirmed);

        $display = Livewire::test(KitchenDisplay::class);
        $display->call('advance', $order->id);
        $display->call('advance', $order->id);

        $order->refresh();

        expect($order->ready_at)->not->toBeNull()
            ->and($order->completed_at)->toBeNull();
    });

    it('does nothing to an order belonging to another restaurant', function (): void {
        $other = restaurant(['name' => 'Somebody Else']);

        $theirs = Order::factory()->for($other)->withStatus(OrderStatus::Confirmed)->create();

        Livewire::test(KitchenDisplay::class)->call('advance', $theirs->id);

        expect($theirs->refresh()->status)->toBe(OrderStatus::Confirmed);
    });

    it('does nothing to an order that is not there', function (): void {
        Livewire::test(KitchenDisplay::class)->call('advance', 9_999)->assertOk();
    });

    it('does nothing to an order with nowhere left to go', function (): void {
        $order = ticket(OrderStatus::Confirmed, ['status' => OrderStatus::Completed]);

        Livewire::test(KitchenDisplay::class)->call('advance', $order->id);

        expect($order->refresh()->status)->toBe(OrderStatus::Completed);
    });
});

describe('who may look at it', function (): void {
    it('refuses a request from somebody who has signed out since the page loaded', function (): void {
        $display = Livewire::test(KitchenDisplay::class);

        auth()->logout();

        $display->call('advance', 1)->assertForbidden();
    });
});
