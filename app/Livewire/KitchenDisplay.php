<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Restaurant;
use App\Support\CompiledAssets;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The screen on the wall in the kitchen.
 *
 * Three columns, one card per live order, one tap to move a card along. It is
 * deliberately not a Filament page: a panel gives you a sidebar, a topbar, a
 * user menu and a breadcrumb trail, all of which are wasted pixels on a screen
 * nobody navigates and half of which are tap targets somebody's elbow will
 * find. This renders on its own full-screen layout with nothing else on it.
 *
 * Updates arrive over the websocket when Reverb is running, and from the poll
 * in the view when it is not. Both call the same render, so a display that
 * lost its socket is late rather than wrong.
 *
 * @see docs/DECISIONS.md #0035, #0036
 */
#[Title('Kitchen')]
#[Layout('layouts.kitchen')]
#[On('echo-private:restaurant.{restaurantId}.kitchen,.order.received')]
#[On('echo-private:restaurant.{restaurantId}.kitchen,.order.status-changed')]
final class KitchenDisplay extends Component
{
    /**
     * The tenant this screen belongs to.
     *
     * Resolved once, at mount, because it is half of the channel name the
     * browser subscribes to and a value that changed between renders would be
     * a subscription that silently stopped matching. `#[Locked]` because it is
     * also the tenancy key every query below is filtered by, and a property
     * the browser can send back is a property the browser can change.
     */
    #[Locked]
    public int $restaurantId = 0;

    public function mount(): void
    {
        $this->restaurantId = Restaurant::current()->id;
    }

    /**
     * Hold every request to the same standard the route does.
     *
     * The route sits behind Filament's Authenticate middleware, but Livewire's
     * own update endpoint does not — it is registered by Livewire under the
     * `web` group, and it cannot simply be moved behind auth because the login
     * screen is itself a Livewire component. So a signed snapshot taken while
     * signed in would otherwise keep working after signing out.
     *
     * `boot()` runs on the first render and on every subsequent request, which
     * is what makes it the right place. The check is the panel's own, so there
     * is still exactly one definition of who counts as staff.
     *
     * @see App\Models\User::canAccessPanel()
     */
    public function boot(): void
    {
        $user = Filament::auth()->user();

        abort_unless(
            $user instanceof FilamentUser && $user->canAccessPanel(Filament::getCurrentOrDefaultPanel()),
            403,
        );
    }

    /**
     * Move one order to the next status, from a single tap on its card.
     *
     * There is no way back. Undo on a touchscreen in a kitchen means a second
     * control next to the first one, and the tap that needs undoing is nearly
     * always the one that was aimed at the control beside it. Fixing a mistake
     * is a job for the dashboard, where there is a keyboard and a mouse.
     *
     * A missing order, an order belonging to another restaurant, and an order
     * with nowhere left to go all do nothing. None of them is worth an error
     * message on a wall-mounted screen; the re-render that follows shows the
     * board as it actually is, which is the answer to all three.
     */
    public function advance(int $orderId): void
    {
        $order = Order::query()
            ->where('restaurant_id', $this->restaurantId)
            ->whereKey($orderId)
            ->first();

        if (! $order instanceof Order) {
            return;
        }

        $next = $order->status->nextOnKitchenDisplay();

        if ($next === null) {
            return;
        }

        $order->status = $next;
        $order->save();
    }

    public function render(): View
    {
        $restaurant = Restaurant::current();
        $orders = $this->liveOrders();

        return view('livewire.kitchen-display', [
            'restaurant' => $restaurant,
            'columns' => [
                $this->column('New', [OrderStatus::Confirmed, OrderStatus::Accepted], $orders),
                $this->column('Preparing', [OrderStatus::Preparing], $orders),
                $this->column('Ready', [OrderStatus::Ready], $orders),
            ],
            'urgencies' => $this->urgencies($restaurant, $orders),
            'pollSeconds' => max(3, (int) config('restaurantline.kitchen.poll_seconds')),
            // Whether the browser will have Echo. False means the poll below is
            // the only thing keeping this screen current, which the header says
            // out loud rather than leaving the kitchen to work out.
            'realtime' => CompiledAssets::exist(),
        ]);
    }

    /**
     * @return Collection<int, Order>
     */
    private function liveOrders(): Collection
    {
        return Order::query()
            ->where('restaurant_id', $this->restaurantId)
            ->live()
            ->with(['items.modifiers', 'customer', 'address'])
            // Oldest first: the board reads top-left to bottom-right in the
            // order things need doing. Falls back to `created_at` because an
            // order confirmed by hand in the dashboard has no confirmation
            // time, and a null would otherwise sort it to the end in Postgres.
            ->orderByRaw('coalesce(confirmed_at, created_at) asc')
            ->get();
    }

    /**
     * @param  list<OrderStatus>  $statuses
     * @param  Collection<int, Order>  $orders
     * @return array{heading: string, orders: Collection<int, Order>}
     */
    private function column(string $heading, array $statuses, Collection $orders): array
    {
        return [
            'heading' => $heading,
            'orders' => $orders
                ->filter(static fn (Order $order): bool => in_array($order->status, $statuses, true))
                ->values(),
        ];
    }

    /**
     * How late each order is, keyed by order id.
     *
     * Computed here rather than in the view so the view stays a list of cards,
     * and keyed rather than hung off the model so nothing has to be mutated to
     * carry it.
     *
     * @param  Collection<int, Order>  $orders
     * @return array<int, string> One of `ok`, `warn` or `late`
     */
    private function urgencies(Restaurant $restaurant, Collection $orders): array
    {
        $warnAtPercent = (int) config('restaurantline.kitchen.warn_at_percent');
        $urgencies = [];

        foreach ($orders as $order) {
            $promised = $order->estimated_minutes ?? ($order->isDelivery()
                ? $restaurant->delivery_prep_minutes
                : $restaurant->collection_prep_minutes);

            if ($promised <= 0) {
                $urgencies[$order->id] = 'ok';

                continue;
            }

            $elapsed = $order->minutesSinceConfirmed();

            $urgencies[$order->id] = match (true) {
                $elapsed >= $promised => 'late',
                $elapsed >= (int) ceil($promised * $warnAtPercent / 100) => 'warn',
                default => 'ok',
            };
        }

        return $urgencies;
    }
}
