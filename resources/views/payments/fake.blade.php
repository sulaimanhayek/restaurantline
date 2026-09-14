{{--
    The fake payment page. Never reachable in production — see
    FakePaymentLinkProvider — and the banner at the bottom says so out loud, so
    that nobody screenshots this into a client demo and calls it the real thing.
--}}
<x-layouts.public :title="'Pay order '.$order->order_number">
    <p class="eyebrow">{{ $order->restaurant->name }}</p>
    <h1>Order {{ $order->order_number }}</h1>

    <div class="row">
        <span class="muted">Subtotal</span>
        <span>{{ $order->subtotalMoney()->format() }}</span>
    </div>

    @if ($order->delivery_fee > 0)
        <div class="row">
            <span class="muted">Delivery</span>
            <span>{{ $order->deliveryFeeMoney()->format() }}</span>
        </div>
    @endif

    <div class="row">
        <span class="muted">Total</span>
        <span class="amount">{{ $order->totalMoney()->format() }}</span>
    </div>

    @if ($paid)
        <p class="muted" style="margin-top:1.25rem">This order has already been paid. Thank you.</p>
    @else
        <form method="POST" action="{{ route('payments.fake.pay', ['reference' => $order->payment_reference]) }}">
            @csrf
            <button type="submit">Pay {{ $order->totalMoney()->format() }}</button>
        </form>
    @endif

    <p class="notice">
        <strong>Test payment page.</strong> No card is asked for and no money moves. The real
        driver sends the customer to Stripe's own hosted page, which is the whole point — card
        details never reach this application. Set <code>PAYMENT_DRIVER=stripe</code> to switch over.
    </p>
</x-layouts.public>
