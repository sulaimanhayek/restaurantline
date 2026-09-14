# restaurantline

[![CI](https://github.com/sulaimanhayek/restaurantline/actions/workflows/ci.yml/badge.svg)](https://github.com/sulaimanhayek/restaurantline/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)

An open-source Laravel boilerplate for putting an AI voice agent on a
restaurant's phone line. The agent answers, reads the menu, takes a delivery or
collection order, prices it, reads it back, and puts it on a screen in the
kitchen. Orders, calls, customers and the menu live in a Filament dashboard the
restaurant can use.

It is built for one person: a freelancer or a small agency with one restaurant
client and a week to make something work. Fork it, add your keys, deploy it.
Every decision in here was made in favour of your first hour with the
repository.

MIT licensed. Take it, rename it, bill for it.

---

## What this is, and what it is not

**The conversation is not ours.** Listening, speaking, interruption,
turn-taking, latency, telephony — all of it belongs to the
[ElevenLabs Agents](https://elevenlabs.io/docs/eleven-agents/overview)
platform. There is no audio in this repository, no WebSocket to a speech model,
no barge-in logic.

What is here is the other half: the Laravel application the agent **calls into**
while it is talking, and the provisioning that wires the two together.

```
                     ┌────────────────────────┐
 caller ──phone──▶   │    ElevenLabs agent    │  ◀── you write none of this
                     └───────────┬────────────┘
                                 │
                                 │  nine webhook tools, during the call
                                 │  one post-call webhook, after it
                                 ▼
                     ┌────────────────────────┐
                     │     restaurantline     │   menu · pricing · hours
                     │    (this repository)   │   orders · state machine
                     └────────────────────────┘   dashboard · kitchen display
```

You write no prompt by hand and click nothing in a dashboard:
`php artisan kitchenline:provision` creates the tools, the agent, the system
prompt and the post-call webhook from a row in your `restaurants` table, and is
safe to run again every time any of that changes.

---

## Four rules the code enforces

These are not style preferences. Three of them are the difference between a
working takeaway and a lawsuit, and all four are enforced in code rather than
in a prompt, because a prompt is a suggestion.

### 1. It never takes a card number

**No endpoint in this application accepts a card number. There is no column for
one. There is no code path that could.**

The caller pays by a Stripe Checkout link texted to them after the call, or in
cash. Which of the two is offered is a per-restaurant setting; if only one is
enabled the agent never raises the subject.

This matters more than it looks. ElevenLabs records the call and keeps the
transcript, so a card number read aloud is a card number in somebody else's
storage, in your logs, and inside PCI DSS scope for you *and* your client. A
scenario in `evals/scenarios/refuses-a-card-number.json` exists specifically to
catch a prompt change that erodes this. The long version, written so you can
hand it to a client who asks for card-by-phone, is in
[`app/Services/Payments/README.md`](app/Services/Payments/README.md).

### 2. It reads the order back before it commits it

`create_order` writes the order as `confirming`, never as `confirmed`. It comes
back with the whole order and the total, for the agent to read aloud.
Only `confirm_order` — a separate call, made after the caller has agreed —
moves it to `confirmed`, and only then does the kitchen see it, the SMS go out
or the payment link get created.

A misheard "no onions" is a remade dish. A misheard address is a lost one.

### 3. It always offers a human

`escalate_to_human` is a real tool, wired to a real transfer number on the
restaurant row. Every restaurant needs an answer to "I want to speak to
someone", and an agent that cannot produce one loses the customer *and* the
restaurant.

### 4. It will not deliver to an address nobody said out loud

`validate_address` returns a signed `address_token`. `create_order` rejects a
token whose address has not been confirmed — the agent must pass
`address_confirmed: true`, which it may only do after reading the address back.
An order with no address at all is a 422; an address the agent has not confirmed
comes back as `ok: false` with a line for it to say instead. Neither writes a
row.

---

## Quick start

You need Docker, and nothing else. No ElevenLabs account yet — every outbound
integration ships with a fake driver, and the fakes are the defaults.

```bash
git clone https://github.com/sulaimanhayek/restaurantline.git
cd restaurantline
cp .env.example .env
docker compose up -d --build
```

Then, inside the container:

```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

That gives you a demo restaurant, a menu of twenty-three dishes across eight
sections — with sizes, spice levels, extras and a lunch deal that is only on
between 11:30 and 3pm on weekdays — and a handful of orders in various states.

| | |
| --- | --- |
| Dashboard | <http://localhost:8000/admin> |
| Kitchen display | <http://localhost:8000/kitchen> |
| Horizon | <http://localhost:8000/horizon> |
| Mailpit | <http://localhost:8025> |

The seeded dashboard login is `owner@embergrill.example` / `password`. It is a
demo seeder; `DemoRestaurantSeeder` is not something to run in production.

Now watch it take an order without a telephone anywhere in sight:

```bash
docker compose exec app php artisan kitchenline:eval
```

That replays nine recorded conversations' worth of tool calls against your own
routes and grades what lands in the database. It should print nine passes and
145 checks. Open the kitchen display and run it again.

To see what would be sent to ElevenLabs — all nine tool definitions and the
whole assembled system prompt — without an account and without sending
anything:

```bash
docker compose exec app php artisan kitchenline:provision --dry-run
```

---

## Putting it on a real phone line

Four things, in this order.

**1. Make this application reachable.** ElevenLabs calls your tool URLs from its
own infrastructure, so `APP_URL=http://localhost:8000` produces an agent that
answers the phone, sounds perfect, and cannot look up a single dish. An ngrok
tunnel is fine for testing. `kitchenline:provision` warns you about this.

**2. Set a real agent token.** `AGENT_API_TOKEN` is the shared secret between
ElevenLabs and your order endpoints. The value in `.env.example` is published in
this repository; `AuthenticateAgent` refuses it outright when `APP_ENV` is
`production`.

```bash
php -r "echo bin2hex(random_bytes(32));"
```

**3. Provision.**

```bash
ELEVENLABS_DRIVER=api php artisan kitchenline:provision
```

It creates one workspace secret holding your bearer token, the nine tools, the
agent, and a post-call webhook — and prints the webhook signing secret **once**,
in a box you cannot miss. Copy it into `ELEVENLABS_WEBHOOK_SECRET`; ElevenLabs
will not show it again, and without it every post-call delivery is rejected as
unsigned. Every id is remembered on the restaurant row and read back before it
is reused, so running it again updates rather than duplicates.

**4. Point a number at it.** Deliberately a separate step behind its own
confirmation, because it changes who answers when a customer rings:

```bash
php artisan kitchenline:provision --phone-number='+442071234567'
```

Then call it.

---

## The nine tools

Each is a standalone ElevenLabs webhook tool pointing at one route, bearer
authenticated, rate limited, and returning `{"ok": …}` with a `say` string
written for a model to read aloud rather than a stack trace to leak.

| Tool | Route | What it is for |
| --- | --- | --- |
| `search_menu` | `POST /api/agent/menu/search` | Find a dish by what the caller called it. Fuzzy, so mishearings survive. |
| `show_menu` | `GET /api/agent/menu` | Read out a section, or the list of sections. |
| `check_availability` | `POST /api/agent/availability` | Are we open, do we deliver there, can we take this now. |
| `opening_hours` | `GET /api/agent/hours` | "What time do you close?" |
| `validate_address` | `POST /api/agent/address/validate` | Geocode, check the delivery radius, return a signed token. |
| `quote_order` | `POST /api/agent/quote` | Price a basket without committing to it. |
| `create_order` | `POST /api/agent/orders` | Write the order as `confirming` and return it to be read back. |
| `confirm_order` | `POST /api/agent/orders/{order}/confirm` | The caller said yes. Kitchen, SMS, payment link. |
| `escalate_to_human` | `POST /api/agent/escalate` | Hand over to a person. |

Plus one inbound webhook, `POST /webhooks/elevenlabs`, which receives the
transcript, the analysis and the call recording after the call ends. Its HMAC
signature is verified — including the timestamp, against a tolerance — before
anything else happens, and an unsigned or stale delivery gets a bare 401 with
the reason logged rather than returned.

The tool descriptions are the agent's instructions, and they are long on
purpose. They live in
[`app/Services/ElevenLabs/ToolDefinitions.php`](app/Services/ElevenLabs/ToolDefinitions.php);
the system prompt is assembled in
[`AgentPrompt.php`](app/Services/ElevenLabs/AgentPrompt.php). Editing either and
re-provisioning is how you change how the agent behaves.

---

## The order, from ring to bag

```
          ─────── the call ───────     ────── the kitchen display ──────

draft ──▶ confirming ──▶ confirmed ──▶ preparing ──▶ ready ──▶ completed
               │             ▲
               ▼         accepted
           cancelled

failed ◀── a call that ended before there was an order to keep
```

`confirming` is where an order sits while the agent reads it back, and
`confirmed` is the furthest a telephone call alone can take it. Everything after
that is somebody tapping the display by the pass — one tap per column, no undo,
because a tablet with flour on it is not the place for a confirmation dialog.
(`accepted` exists for an explicit "yes, we've got it" and shows in the same
column as `confirmed`; nothing requires it.)

`create_order` is idempotent on `(restaurant_id, elevenlabs_conversation_id)`,
because ElevenLabs retries a tool call that times out and a takeaway that makes
two of everything on a slow network is worse than one that makes none.

---

## Evals

The question a boilerplate has to answer is "how do I know it still works after
I change the menu / the prompt / the pricing?" There are two answers, because
there are two things that can break.

```bash
php artisan kitchenline:eval                 # fake: free, offline, what CI runs
php artisan kitchenline:eval --mode=live     # live: real agent, real money
php artisan kitchenline:eval --tag=safety    # just the ones that matter most
```

**Fake mode** replays each scenario's tool calls against this application —
real routes, real bearer token, real validation, real menu matching, real
pricing, real state machine — and grades the rows that come out. It costs
nothing and needs no account. What it cannot tell you is whether the agent would
have *decided* to make those calls.

**Live mode** hands the whole conversation to ElevenLabs: a model plays the
caller, your provisioned agent answers, and a judge model grades the transcript
against each scenario's written criteria. It is the only way to test the four
rules above, because all four are things the agent *says*. It is billed per
scenario.

Both modes read the same files, in `evals/scenarios`. Adding one is the cheapest
documentation you will ever write of what "working" means for a particular
restaurant:

```json
{
    "title": "A delivery order under the minimum",
    "tags": ["delivery", "pricing"],
    "caller": "You want a chicken pitta delivered to 3 Hanbury Street, E1 6QR.",
    "calls": [
        { "tool": "quote_order", "params": { "…": "…" },
          "expect": { "ok": false, "error": "below_minimum" } }
    ],
    "expect": { "no_order": true },
    "criteria": [
        { "name": "Offered a way forward",
          "goal": "Offered to add to the order or to collect, rather than only refusing." }
    ]
}
```

---

## Everything that talks to the outside world has a fake

The fake is always the default, so a fresh clone runs, and the whole test suite
runs, with no accounts and no keys.

| | `.env` | Real | Fake does |
| --- | --- | --- | --- |
| Voice agent | `ELEVENLABS_DRIVER` | ElevenLabs | Keeps a make-believe workspace, so `provision` works offline |
| Geocoding | `GEOCODER_DRIVER` | Google | Knows two dozen east London streets, three of them ambiguous on purpose |
| SMS | `SMS_DRIVER` | Twilio | Writes the message to the log and the dashboard, and never to a log in production |
| Payments | `PAYMENT_DRIVER` | Stripe | Hosts a local checkout page at `/pay/{reference}` |

Live evals are the one thing that has no fake, and that is deliberate: a
made-up transcript, graded, printed as a pass, would be a lie with
consequences.

---

## Before you deploy it

- **Configure `TrustProxies`.** It ships unconfigured, which is correct for a
  repo that does not know what it will be deployed behind. Behind a load
  balancer or Cloudflare, that makes `$request->ip()` — the rate limiter's
  fallback key — and HTTPS detection both wrong. Add
  `$middleware->trustProxies(at: '*')` to `withMiddleware()` in
  `bootstrap/app.php` if only your balancer can reach the application, or name
  the proxy addresses if it cannot.
- **Generate `AGENT_API_TOKEN` and `APP_KEY`.** See above; the example token is
  refused in production, and it is refused because `cp .env.example .env`
  followed by a deploy is the likeliest single route to an open
  order-creation endpoint on the public internet.
- **Point `ELEVENLABS_AUDIO_DISK` at S3.** Call recordings are the largest thing
  this stores and the only thing that cannot be regenerated.
- **Move the queue off `sync`.** Confirmation SMS and payment links are queued;
  Horizon is already wired up and in the compose file.
- **Check the Docker Compose file is not your production plan.** It publishes
  PostgreSQL and Redis on loopback with the password `secret`, which is right
  for a laptop and nowhere else.

---

## Built for one restaurant, shaped for many

Every tenant-scoped table carries a `restaurant_id` from day one, and every
query is scoped by it. There is exactly one seeded restaurant and no tenant
switcher, no subdomain routing and no per-tenant config resolution — none of
which you need for your first client, and all of which are much harder to
retrofit into a schema than into a set of routes.

**Not in scope, by decision:** POS integrations, driver dispatch and tracking,
loyalty schemes, table reservations, native mobile apps.

---

## Working on it

```bash
docker compose exec app composer check   # Pint, PHPStan level 6, the whole suite
docker compose exec app composer test    # just Pest
docker compose exec app composer evals   # just the scenarios
```

The suite runs against a real PostgreSQL — `restaurantline_testing`, created for
you by the compose file — because the menu matcher leans on `pg_trgm` and
`jsonb`, and a SQLite suite would go green while the real query was broken.

`declare(strict_types=1)` at the top of every PHP file. Pint and PHPStan level 6
are not advisory: `composer check` is what CI runs, and CI also builds a fresh
clone from `.env.example`, seeds it, and runs the eval scenarios against it, so
a stale example file is caught here rather than by the next person to fork this.

Every decision that was not obvious — and the arguments against the ones that
were — is written down in [`docs/DECISIONS.md`](docs/DECISIONS.md), newest last.
If you are wondering why something is the way it is, it is very likely in there.

---

## Stack

Laravel 12 · PHP 8.3 · Filament v4 · PostgreSQL 17 · Redis · Horizon · Reverb ·
Livewire · Pest · Docker Compose

## Licence

MIT. See [LICENSE](LICENSE).
