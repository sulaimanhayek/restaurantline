{{--
    One root element, three columns, one card per live order.

    `wire:poll` is not a fallback that switches on when the websocket drops —
    it runs the whole time. A kitchen that has stopped receiving orders has no
    way of telling that apart from a quiet evening, so the cost of the extra
    request every fifteen seconds is worth paying to make that impossible.

    @see docs/DECISIONS.md #0036
--}}
<div class="kitchen" wire:poll.{{ $pollSeconds }}s>
    <header class="kitchen-header">
        <h1>{{ $restaurant->name }}</h1>

        {{-- Alpine, from Livewire's own bundle. `wire:ignore` because this is
             browser state and a re-render should not reset it. --}}
        <div wire:ignore
             x-data="{
                 state: @js($realtime ? 'connecting' : 'polling'),
                 init() {
                     const connection = window.Echo?.connector?.pusher?.connection;

                     if (! connection) {
                         this.state = 'polling';
                         return;
                     }

                     const read = (name) => this.state = name === 'connected' ? 'live' : 'lost';

                     read(connection.state);
                     connection.bind('state_change', (change) => read(change.current));
                 },
             }"
             class="pill"
             :data-connection="state">
            <span class="dot"></span>
            <span x-text="{ live: 'Live', lost: 'Reconnecting', connecting: 'Connecting', polling: 'Polling' }[state]">Polling</span>
        </div>

        <div class="spacer"></div>

        <span wire:ignore
              class="pill"
              x-data="{ now: '' }"
              x-init="const tick = () => now = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); tick(); setInterval(tick, 15000)"
              x-text="now"></span>

        <a class="header-link" href="{{ url('/admin') }}">Dashboard</a>
    </header>

    <main class="board">
        @foreach ($columns as $column)
            <section class="column">
                <div class="column-head">
                    <span>{{ $column['heading'] }}</span>
                    <span class="count">{{ $column['orders']->count() }}</span>
                </div>

                <div class="column-body">
                    @forelse ($column['orders'] as $order)
                        <article class="ticket"
                                 wire:key="order-{{ $order->id }}"
                                 data-urgency="{{ $urgencies[$order->id] ?? 'ok' }}">
                            <div class="ticket-head">
                                <span class="ticket-number">{{ $order->order_number }}</span>
                                <span class="ticket-timer">{{ $order->minutesSinceConfirmed() }}m</span>
                            </div>

                            <div class="ticket-tags">
                                <span class="tag" data-kind="{{ $order->isDelivery() ? 'delivery' : 'collection' }}">
                                    {{ $order->fulfilment_type->label() }}
                                </span>

                                @if ($order->customer?->name !== null)
                                    <span class="tag">{{ $order->customer->name }}</span>
                                @endif

                                @if ($order->payment_status->isOutstanding())
                                    <span class="tag" data-kind="unpaid">{{ $order->payment_status->ticketLabel() }}</span>
                                @endif
                            </div>

                            <ul class="ticket-items">
                                @foreach ($order->items as $item)
                                    <li>
                                        <div class="item-line">
                                            <span class="item-qty">{{ $item->quantity }}&times;</span>
                                            <span>{{ $item->name }}</span>
                                        </div>

                                        @foreach ($item->modifiers as $modifier)
                                            <div class="item-extra">{{ $modifier->ticketLabel() }}</div>
                                        @endforeach

                                        @if (filled($item->notes))
                                            <div class="item-note">{{ $item->notes }}</div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>

                            @if (filled($order->notes))
                                <div class="ticket-note">{{ $order->notes }}</div>
                            @endif

                            @if ($order->isDelivery() && $order->address !== null)
                                <div class="ticket-address">{{ $order->address->spoken() }}</div>
                            @endif

                            @if ($order->status->kitchenActionLabel() !== null)
                                <button type="button"
                                        class="ticket-advance"
                                        wire:click="advance({{ $order->id }})">
                                    {{ $order->status->kitchenActionLabel() }}
                                </button>
                            @endif
                        </article>
                    @empty
                        <p class="column-empty">Nothing here</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </main>
</div>
