# Payments

**restaurantline never accepts a card number.** Not over the phone, not through
an API endpoint, not in a database column, not in a log line. There is no code
path here that could, and adding one is not a small change — it is a decision to
build and operate a cardholder data environment.

This file exists because that rule is easy to erode. Somebody's client will ask
for it. This is the answer to give them.

## What happens instead

When an order is confirmed, the caller pays in one of two ways:

- **A payment link, sent by SMS after the call.** The link goes to a page hosted
  by the payment provider — Stripe Checkout, in the driver that ships here. The
  customer types their card into Stripe's page, on their own phone. The card
  number never reaches this server, and never reaches the voice agent.
- **Cash**, to the driver or at the counter.

Which of the two is on offer is per-restaurant: `accepts_card_link` and
`accepts_cash` on the `restaurants` table. If exactly one is enabled, the
application picks it and the agent never raises the subject. If both are, the
agent asks, and passes the answer to the confirm endpoint.

## Why not just read the card number out on the phone

Three reasons, in the order a client will find them convincing.

**The call is recorded.** ElevenLabs keeps conversation audio and a transcript,
and so does this application — that is the whole point of the Calls screen in
the dashboard. A card number read aloud on a recorded line is a card number
stored in two systems, in a form nobody can redact reliably, held by a company
whose security posture the restaurant has never assessed. PCI DSS is explicit
that recording sensitive authentication data is prohibited outright: CVV may not
be stored after authorisation under any circumstances, and a recording is
storage.

**It changes which compliance regime the restaurant is in.** Taking card details
over the phone into a system you operate puts you squarely inside PCI DSS scope,
with the assessment, the segmentation, the quarterly scanning and the evidence
that implies. Redirecting the customer to a provider-hosted payment page is
specifically the arrangement designed to keep you out of it — the lightest
self-assessment questionnaire exists for exactly this pattern. A restaurant with
four staff and a laptop cannot operate the first arrangement. Nor can the
freelancer who deployed this for them, who would be the one holding the risk.

**An LLM is the wrong thing to hand a card number to.** The agent's transcript
goes to a model provider. It goes into the eval harness in this repo. It ends up
in a log line somebody pastes into an issue. Every one of those is a place a
card number should not be, and the only reliable way to keep it out of all of
them is for it never to exist.

The unglamorous version of all three: the freelancer who forked this repo would
be personally responsible for a breach, and a breach of card data is the one
kind that comes with fines and a forensic investigation.

## What is enforced in code

- No request class accepts a card field. `App\Rules\NoCardNumber` is applied to
  the free-text fields a caller's words land in — the customer's name and the
  order notes — and rejects anything that looks like a card number, so a caller
  who reads one out anyway does not get it written to the database.
- `PaymentLinkProvider` takes an `Order` and returns a URL. There is no method
  on it that accepts card data, by design. See the interface's docblock.
- `App\Enums\PaymentStatus` has no state meaning "card taken by phone".
- The Stripe driver creates a Checkout Session. It never posts card fields.

If you are reviewing a change to this directory, the question to ask is whether
any of the four statements above is still true afterwards.

## Drivers

Bound in `AppServiceProvider::register()`, selected by `PAYMENT_DRIVER`.

| Driver   | What it does                                                        |
| -------- | ------------------------------------------------------------------- |
| `fake`   | Default. A link to a page in this application that marks the order paid. No network, no account. Refuses to run in production. |
| `stripe` | A real Stripe Checkout Session. Needs `STRIPE_SECRET`, and `STRIPE_WEBHOOK_SECRET` for the webhook that marks the order paid. |

### Adding another provider

Implement `PaymentLinkProvider`, add a case to the `match` in
`AppServiceProvider::register()`, and add the driver name to the comment above
`payments.driver` in `config/restaurantline.php`. If the provider signs its
webhooks — it should — mirror `App\Http\Middleware\VerifyStripeSignature`
rather than trusting the payload.

Whatever you add, it hands the customer a URL. If the SDK you are reaching for
wants a card number, you are integrating it at the wrong layer.
