{{--
    Where the payment provider sends the customer afterwards.

    Deliberately says very little. The order number is in the URL and an order
    number is guessable, so this page must not become a way to read somebody
    else's order back — no items, no address, no total, no name. The customer
    already knows what they ordered; all they need from this page is that the
    tap worked.
--}}
<x-layouts.public title="Thank you">
    <p class="eyebrow">{{ $restaurant->name }}</p>
    <h1>Thank you</h1>

    <p class="muted">
        Your order is with the kitchen. If anything needs checking we'll call you back on the
        number you rang from.
    </p>

    @if ($restaurant->phone_number !== null)
        <p class="muted">Questions? Call us on {{ $restaurant->phone_number }}.</p>
    @endif
</x-layouts.public>
