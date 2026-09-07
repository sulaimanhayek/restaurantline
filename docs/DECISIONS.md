# Decisions

A running log of architectural decisions, defaults chosen without asking, and
places where this codebase deliberately diverges from the original spec or from
what the ElevenLabs documentation says.

Format: newest last. Each entry states the decision, the reasoning, and — where
relevant — what it would cost to reverse.

---

## 0001 — Money is stored as integer minor units

**Decided:** confirmed with the project owner before Phase 1.

All monetary columns (`price`, `price_delta`, `subtotal`, `delivery_fee`,
`total`, `minimum_order_value`, …) are `bigint` holding the currency's minor
unit — pence for GBP, cents for USD. `restaurants.currency` holds the ISO-4217
code.

**Why:** no floating point error anywhere in the pricing path; Stripe's API
takes minor units natively so there is no conversion at the payment boundary;
and JSON returned to the agent contains plain integers rather than strings that
an LLM might try to do arithmetic on. Formatting for humans happens once, in a
`Money` value object.

**Reversal cost:** high. Every monetary column, cast, factory and assertion.

---

## 0002 — Modifier groups are shared, with per-item overrides

**Decided:** confirmed with the project owner before Phase 1.

`modifier_groups` are reusable rows scoped to a restaurant. `menu_items` link to
them through the `menu_item_modifier_group` pivot, which carries `sort_order`
plus three nullable override columns: `min_selections_override`,
`max_selections_override`, `is_required_override`.

**Why:** a "Size" group should be authored once and reused, but a side dish may
need it optional where a main course needs it required. Nullable overrides give
that without duplicating group definitions — and duplicated groups are
especially harmful here, because each copy accumulates its own drifting set of
`spoken_aliases` and the agent's matching quality degrades.

Resolution is `override ?? group value`, implemented in one place so it cannot
drift.

**Reversal cost:** medium. Dropping the override columns is easy; adding them
later means backfilling every item that had been duplicated to work around
their absence.

---

## 0003 — Modifier prices are signed deltas with a per-item override

**Decided:** confirmed with the project owner before Phase 1.

`modifiers.price_delta` is a signed `bigint`. The sparse `menu_item_modifier`
pivot carries `price_delta_override`, used only where an item genuinely prices a
modifier differently ("extra cheese" costing more on a large pizza than on a
burger).

`PricingService` resolves `override ?? base` and is the only code permitted to
do monetary arithmetic.

**Reversal cost:** low-medium.

---

## 0004 — Removals and swaps are typed modifier rows, not free text

**Decided:** confirmed with the project owner before Phase 1.

`modifier_groups.selection_type` is an enum (`single`, `multi`).
`modifiers.kind` is an enum (`option`, `addon`, `removal`, `swap`).

"No onions" is a real seeded `Modifier` row with `kind = removal`,
`price_delta = 0`, and `spoken_aliases` of `["no onions", "without onions",
"hold the onions"]`.

**Why:** it collapses four concepts onto one code path. The menu-matching
service, the pricing service, the order snapshot and the kitchen ticket all
treat a removal exactly like a size or an add-on. The alternative — letting the
agent write "no onions" into a free-text notes field — is unpriceable,
unsearchable, and makes it impossible to answer "which modifications do callers
actually ask for?", which is precisely the question this system exists to
surface.

**Reversal cost:** medium.

---

## 0005 — Schema is multi-tenant-shaped, but there is no tenancy layer

Every tenant-scoped table carries a `restaurant_id` foreign key from day one and
every query traverses the restaurant relationship. There is no tenant resolver,
no global scope, no subdomain routing and no per-tenant config resolution.

**Why:** the spec calls for exactly one restaurant now, but wants adding real
multi-tenancy later to be additive rather than a migration rewrite. The columns
and relationships are the expensive part; the resolver is a day's work whenever
it is genuinely needed.

---

## 0006 — ElevenLabs findings that contradict or extend the original spec

Verified against the live documentation on 2026-09-06. The platform's docs are
served under two path prefixes that mirror each other — `/docs/agents-platform/`
and `/docs/eleven-agents/`. Both resolve; `agents-platform` appears to be the
canonical one for the API reference pages.

### Tools are standalone API resources, not inline agent config

The spec's provisioning step 3 implies generating tool definitions and passing
them to the agent as part of its config. That is **not** how the current API
works.

Tools are created as their own resource:

```
POST https://api.elevenlabs.io/v1/convai/tools
{ "tool_config": { "type": "webhook", "name": …, "description": …, "api_schema": { … } } }
→ 200 { "id": "<tool_id>", "tool_config": {…}, "access_info": {…}, "usage_stats": {…} }
```

The agent then references them by id:

```
POST https://api.elevenlabs.io/v1/convai/agents/create
{ "conversation_config": { "agent": { "prompt": { "prompt": …, "llm": …, "tool_ids": ["<tool_id>", …] }, … } } }
```

**Consequence for `kitchenline:provision`:** the command must reconcile tools
first — create or update each of the nine tool definitions, collect their ids —
and only then create or update the agent with that `tool_ids` array. It must
also persist the tool ids so that a second run updates rather than duplicates.
This is handled by storing a tool-id map on the restaurant.

### Tool definition shape

`api_schema` carries `url`, `method` (GET/POST/PUT/PATCH), and separate JSON
Schema objects per parameter location: `path_params_schema`,
`query_params_schema`, `request_body_schema`, `request_headers`. Path
parameters are interpolated into the URL with curly braces, e.g.
`/api/agent/orders/{order}/confirm`.

Two recent additions worth knowing about: server tools support a configurable
body content type (`application/json` or `application/x-www-form-urlencoded`),
and webhook tool timeouts now go up to 300 seconds. We use JSON and leave the
timeout at the default; none of our endpoints should take more than a couple of
seconds, and a voice caller will not wait.

### Post-call webhook signature

Header: `ElevenLabs-Signature` (matched case-insensitively — the spec writes it
lowercase, which is fine, HTTP header names are case-insensitive and Laravel
normalises them).

Value format: `t=<unix_timestamp>,v0=<hex_hmac>`

The signed payload is `"{timestamp}.{raw_request_body}"`, hashed with
HMAC-SHA256 using the webhook secret, hex-encoded. The reference
implementations reject timestamps older than **30 minutes (1800 seconds)**.

Two things this implies for our controller:

1. Verification must run against the **raw** request body, before any JSON
   decoding or middleware that might re-serialise it.
2. Comparison must be constant-time (`hash_equals`).

Event types are `post_call_transcription`, `post_call_audio` and
`call_initiation_failure`. All three share a top-level envelope of
`{ "type": …, "event_timestamp": <unix>, "data": { … } }`.

**Caveat, recorded honestly:** the official docs delegate signature verification
to their JS/Python SDKs and do not spell out the wire format on the page itself.
The format above is corroborated by community references and SDK behaviour, and
our implementation is written against it — but it is the one part of this
integration that has not been confirmed against a real signed request. There is
a test fixture and a `FakeElevenLabsSignature` helper so this can be re-verified
in minutes once a live webhook is available. **Re-check this before shipping to
a real restaurant.**

### Twilio phone number attachment is UI-only in the documented flow

The native Twilio integration page documents importing a number through the
ElevenLabs dashboard (label, phone number, Twilio SID, Twilio auth token) and
assigning an agent to it from a dropdown. It documents no REST endpoint for
this.

**Consequence:** `kitchenline:provision` ends by printing the phone number
attachment as a **manual next step** rather than attempting it. This matches the
spec's step 5, which already anticipated it.

### No official PHP SDK

Confirmed — there is none. All access goes through one `ElevenLabsClient`
service wrapping Laravel's HTTP client, with typed methods, so a forker has a
single file to read and a single seam to fake.

---

## 0007 — Artisan command namespace is `kitchenline:`

The project is named `restaurantline`, but the spec names the commands
`kitchenline:provision`, `kitchenline:import-menu` and `kitchenline:eval`.
Keeping the spec's names for now; this is a one-line change in each command
should the owner prefer `restaurantline:`. Flagged rather than silently
resolved.

---

## 0008 — The test suite runs against PostgreSQL, not SQLite

`phpunit.xml` points at a `restaurantline_testing` database on the same
PostgreSQL server the app uses. `docker/postgres/init/10-create-test-database.sql`
creates it the first time the postgres volume is initialised.

**Why:** the menu matcher (Phase 2) is built on `pg_trgm` similarity and the
availability queries use `jsonb`. A SQLite suite would happily go green on a
query PostgreSQL would reject, which is the most expensive kind of passing
test — it costs a forker an afternoon on their first deploy rather than five
seconds in CI.

**Cost:** the suite needs a running database, so it is roughly two seconds
slower to start and cannot run without Docker. `docker compose up` is already
the documented quickstart, so this asks nothing new of a forker.

If you already have a `postgres-data` volume from an earlier checkout, the init
script will not re-run. Either `docker compose down -v`, or:

```
docker compose exec postgres createdb -U restaurantline restaurantline_testing
```

---

## 0009 — A removal modifier is named after the ingredient, not the instruction

Removal rows are `Onions`, `Pickles`, `Mayo` — not `No Onions`. The negation
comes from `ModifierKind::Removal`, whose `ticketPrefix()` renders `NO `.

**Why:** the first draft of the sample menu named them `No Onions`, and the
kitchen ticket came out reading `NO No Onions`. Naming the ingredient keeps one
source of truth for the negation and lets the same row be rendered differently
in each context — `NO Onions` on a ticket, "no onions" when the agent reads the
order back. The `spoken_aliases` still carry every phrasing a caller uses
(`no onions`, `without onions`, `hold the onions`), because that is what the
matcher searches.

**Watch out:** `matchableTerms()` includes the name, so a bare "onions" matches
a removal. Phase 2's matcher must weight aliases above names for removal rows,
or scope modifier matching to a chosen item and group. Noted here so it is not
rediscovered as a bug.

---

## 0010 — PHPStan level 6 with `checkModelProperties`, and three narrow ignores

`checkModelProperties: true` is what makes the `@property` docblocks on the
models load-bearing: a misspelled `$order->totl` fails the build rather than
silently reading null. Keeping it costs three ignores, each scoped by path and
message rather than switched off globally:

1. **`Factory::definition()` return type** (`database/factories/*`). Larastan
   wants the array keyed by model properties. Its own syntax for expressing
   that — `array<model property of X, mixed>` — is rejected by the PHPDoc
   parser bundled with Larastan 3.11, so the stricter form is not actually
   available. Revisit if a later release parses it.
2. **Pest closure binding** (`tests/*`). Pest binds test closures to the
   `TestCase` at runtime; PHPStan sees an unbound closure and reports every
   `$this->` and every `$this->seed()` as missing. The ignore matches only
   `Pest\PendingCalls\TestCall`, so genuine errors in test files — a
   misspelled model property, a method that does not exist — are still caught.
3. **Expectation template resolution** (`tests/*`). Long `->and()` chains over
   nullable values defeat the generic inference in `Pest\Expectation`.

`reportUnmatchedIgnoredErrors` is off, so an ignore that stops matching does not
break the build — but it also will not tell you it is dead. Worth a periodic
look.

---

## 0011 — The minimum order value applies to delivery only

A takeaway that turns away a customer standing at the counter because they only
wanted chips does not exist. Minimums exist to make a driver's round trip worth
running, so `PricingService` charges the restaurant's `minimum_order_value`
against delivery orders and exempts collection.

Operators who disagree flip `PRICING_MINIMUM_APPLIES_TO_COLLECTION=true`. It is
config rather than a schema column because it is a policy that applies to the
whole business, not a per-restaurant fact, and adding a column now would mean a
migration for every forker who never changes it.

**Reversing it** is one config default.

---

## 0012 — Distances are haversine on plain latitude and longitude, not PostGIS

Delivery radius, fee bands and the "we don't come that far" sentence all need a
distance between two points. `App\Support\Distance` computes it with the
haversine formula on the `latitude`/`longitude` columns every geocoded address
already has.

PostGIS would be more precise. Over the three to five kilometres a single
kitchen serves, "more precise" means a metre or two — comfortably inside the
error of the geocoder that produced the coordinates in the first place. What it
would cost is a database extension in the first hour with the repo, a heavier
Docker image, and a hosting requirement a forker on a shared Postgres may not be
able to meet. The optimisation target is that first hour.

What this is explicitly **not** is driving distance. A river between two points
a kilometre apart makes them a ten-minute drive apart. A client whose delivery
area is shaped by geography rather than by radius needs a routing API.

**Reversing it** is contained: every distance in the application goes through
`Distance`, and `DeliveryFeeRule` reads metres and nothing else. Swapping in
PostGIS or a routing provider means reimplementing one class, not rewriting the
callers.

---

## 0013 — Menu matching blends two trigram metrics in SQL, 60/40

The matcher scores a caller's words against every menu item name and every
`spoken_aliases` entry, in one PostgreSQL query, as

```
0.6 × word_similarity(query, term) + 0.4 × similarity(query, term)
```

taking the greater of the score for the raw normalised query and for the
filler-stripped one, and keeping the best-scoring term per item via
`DISTINCT ON`.

**Why both metrics.** They fail in opposite directions. `similarity()` compares
whole strings, so "burger" against "Ember Chicken Burger" scores badly purely
because the item name is longer. `word_similarity()` finds the best-matching run
inside the longer string, which fixes that — but on its own it scores "chicken"
at nearly 1.0 against every chicken dish on the menu, so nothing is ever a clear
winner and the ambiguity check downstream never fires. Weighted together, an
exact name or alias still wins outright while near-misses stay close enough to
be offered as alternatives. That gap is what `isAmbiguous()` reads, so the
weights are load-bearing for whether the agent asks or assumes.

**Why both query forms.** Stripping filler helps "can I get the wings please"
and hurts an item whose own name contains a stripped word. Scoring both and
taking the maximum costs one more term in the same query.

**Why in SQL.** `word_similarity()` is impractical to reimplement in PHP, and
the alternative — loading the whole menu and scoring in the application — is
what the Phase 1 migration comment originally assumed. That assumption is
reversed; the comment on the `menu_items` trigram index now says so. This is
also the concrete reason the test suite needs PostgreSQL (#0008).

**What is not indexed.** Item names use a GIN trigram index. Aliases cannot:
they are unnested from `jsonb` at query time. For a single restaurant's menu —
hundreds of rows, not millions — the sequential scan is not measurable. A fork
running thousands of items per tenant should move aliases into their own table.

**Thresholds** live in `config/restaurantline.php` under `menu`, not in the
code: `minimum_confidence` (0.3) is the floor for being a candidate at all,
`confident_threshold` (0.62) is "add it without asking", and `ambiguity_margin`
(0.08) is how close the runner-up has to be before the agent asks which one.
Tuning them against a real client's menu is expected, and is exactly what the
Phase 9 eval harness measures.

**Reversing it** means rewriting one private method, `scoreItems()`. The public
surface — `search()` returning a `MenuMatchResult` — is independent of how the
scoring happens.

---

## 0014 — Confidence gaps are rounded before they are compared

Both `MenuMatchResult::isAmbiguous()` and
`AddressValidationResult::isAmbiguous()` round the gap between the top two
candidates to four decimal places before testing it against the margin.

Without the rounding, two candidates *exactly* one margin apart are declared
unambiguous by a floating-point artefact: `1.0 - 0.95` is
`0.050000000000000044` in binary floating point, which is greater than `0.05`.
The fake geocoder produces exactly that pair for "42 Cheshire Street" — a house
and a flat at the same address, the case where the agent most needs to ask.

Four decimal places is not arbitrary: it is the precision both services round
their confidences to, so nothing below it was ever meaningful.

---

## 0015 — The cart is stateless; the server holds nothing until an order exists

`POST /quote` and `POST /orders` each take the whole cart in the request body.
There is no server-side session, no draft row and no cached cart keyed by
conversation. The first thing the server persists is the order itself, created
in `confirming`.

The agent already holds the conversation in its context — it has to, to talk —
so asking it to resend a structure it is already holding costs a few hundred
tokens and buys three things: every tool call is independently testable with
`curl`, every tool call is safe to retry, and there is no draft lifecycle to
build (expiry, orphan cleanup, abandoned carts cluttering the dashboard).

The cost is real: a caller who says "actually make that two" makes the agent
rebuild and resend the cart, and a long order sends a growing payload on every
quote. For a takeaway order — rarely more than a dozen lines — that is cheap.

**Reversing it** toward a server-held draft means adding endpoints, not
changing existing ones: `/quote` and `/orders` would keep working as they are.

---

## 0016 — Responses carry `_spoken` companions, and `say` only where the endpoint's job is an utterance

Every money, distance and time field in an agent response is paired with a
ready-to-read string:

```json
{ "total": 2549, "total_spoken": "25 pounds 49" }
```

A language model asked to read `2549` aloud will eventually say "two thousand
five hundred and forty nine", and money is the worst possible place to discover
that. Pairing the fields means the machine-readable value and the spoken value
can never disagree, because one is derived from the other.

A composed `say` sentence is returned only by the three endpoints whose entire
purpose is to produce an utterance — the quote read-back, the order
confirmation, and the escalation hand-off. Everywhere else the model composes
its own sentence from the data, so a forker restyling the agent's voice edits
the Blade prompt template rather than a controller.

**Reversing it** in either direction is a per-endpoint change, not a contract
rewrite: adding `say` to an endpoint is additive, and ignoring it costs nothing.

---

## 0017 — A domain failure is HTTP 200 with `ok: false`

"We don't sell sushi", "that address is outside our delivery area" and "that's
below our minimum" are not errors. They are ordinary things that happen in an
ordinary phone call, and the agent needs a sentence for each of them.

So every agent endpoint returns HTTP 200 with a body of the shape

```json
{ "ok": false, "error": { "code": "outside_delivery_area", "say": "..." } }
```

for anything the conversation can recover from. Real 4xx is reserved for the
three things that are genuinely wrong with the *request* rather than with the
order: a bad or missing bearer token (401), a malformed payload (422), and rate
limiting (429).

This also insures against a platform detail we have not verified: if
ElevenLabs does not surface a non-2xx response body to the model, a 404 becomes
a generic tool failure and the caller hears the agent stall. A 200 always
reaches the model with a sentence in it.

**Unhandled exceptions are the exception.** They return HTTP 500 — a real
server error should be visible as one to monitoring — but with the same safe
JSON body and never a stack trace, which is a constraint the README states and
a test enforces.

---

## 0018 — The agent refers to menu items and modifiers by slug

`{"item": "ember-chicken-burger"}`, not `{"item": 47}`.

A hallucinated integer is still a valid integer: 47 instead of 48 silently
orders the wrong dish, and neither the transcript nor the ticket gives anyone a
clue. A hallucinated slug almost always fails to resolve, which turns a silent
wrong order into a loud "I couldn't find that" the agent can recover from. Slugs
also make the transcript, the request log and the eval fixtures readable without
a database to hand.

This required one pre-release change: modifier slugs were unique per modifier
group and are now unique per restaurant. The migration was edited in place
rather than superseded, because nobody has run it anywhere yet and a forker's
first `php artisan migrate` should not replay a correction to a schema that has
never shipped.

**Reversing it** means changing the resolver in `CartAssembler` and the tool
schemas the provisioning command generates. The database is untouched either
way — both columns exist regardless.

---

## 0019 — A confirmed address travels as a signed token, not as free text

`POST /address/validate` persists nothing. It returns each candidate with an
opaque `address_token`: the geocoded attributes, signed with the application key
and stamped with an issue time.

`POST /orders` will not accept a delivery address any other way. It verifies the
signature, rejects a token older than the configured TTL, writes the `Address`
row itself, and sets `verified_at` only when the agent also passes
`address_confirmed: true`.

Two different guarantees, deliberately separated:

- **The signature** proves the address came out of the geocoder rather than out
  of the model. Without it a hallucinated street reaches a driver, and the whole
  address-validation step becomes theatre — the agent could simply invent the
  address it wished the caller had given.
- **`verified_at`** records that the agent asserted the caller heard it read
  back and agreed. Nothing server-side can verify that; it is an assertion, and
  it is stored as one, timestamped, so a disputed delivery can be traced to the
  turn in the transcript where it was made.

The alternative — a tenth endpoint, `POST /address/confirm` — was rejected to
keep the tool count at nine. Every extra tool is another thing in the agent's
context, another schema to provision, and another opportunity for it to be
called in the wrong order.

**Reversing it** is contained to `AddressToken` and the order-creation
controller.

---

## 0020 — `POST /orders` refuses an order when the kitchen is closed

`POST /availability` has always answered honestly about whether the restaurant
is open. `POST /orders` did not check, and that turned out to matter: it is the
endpoint that must not depend on the agent having called the other one first.

Found by running the thing. An order taken at 09:00 for a kitchen that opens at
17:00 rolled silently forward to the next opening, and the agent read the wait
out as "about 1026 minutes". Nothing errored; the caller was simply told a
number no human would say, for food that would arrive that evening.

Two changes, because the absurd case and the merely bad case are different:

- A closed kitchen is refused outright, with `restaurant_closed` and a sentence
  naming the next opening. Scheduled orders are not in scope, and quietly
  inventing one on a caller's behalf is worse than declining.
- Past roughly two hours, a wait is spoken as a clock time rather than a count
  of minutes — "around 4pm", not "about 180 minutes". A legitimately open
  kitchen with a long prep time still produces a number nobody says out loud.

**Reversing it** — to support scheduled orders — means removing the check and
giving the endpoint an explicit `scheduled_for`, not letting the rollover come
back implicitly.

---

## 0021 — Datetime columns are cast through `UtcDateTime`, not Laravel's `datetime`

Every timestamp column in this schema is `timestamp without time zone`. The
database holds bare digits; the application supplies the convention that they
mean UTC. Laravel's `datetime` cast does not enforce that convention on write —
it formats whatever Carbon it is handed, offset and all, and throws the offset
away.

That is harmless while everything is written from `now()`, which is UTC. It
stops being harmless the moment a restaurant-local time is stored, and this
application deals in restaurant-local time constantly: opening hours, prep
estimates, and everything a caller is told are all local by nature.

Found by running the thing. A ready time of 16:00 in London went into the
column as the digits "16:00" and came back out as 16:00 UTC — an hour late. The
order said five o'clock, the API's `estimated_ready_at` was wrong, the spoken
wait was wrong, and the kitchen display would have counted down to the wrong
minute. Nothing anywhere threw.

`App\Casts\UtcDateTime` converts on the way in and reads the same convention
back out. It is applied to every `datetime` cast in every model rather than to
the columns known to be affected, because the fragile fix is the one that
reintroduces the bug the next time a local time meets a column: "a datetime
column holds a true instant" should be true by construction, not by a list
somebody has to maintain.

Deliberately **not** applied to `date` columns. Converting a local midnight to
UTC lands it at 23:00 the day before, so a date column would store the wrong
day — and `OpeningHourOverride::$date` on the wrong day is a full day of orders
taken for a kitchen nobody is standing in. That cast stays `immutable_date`,
and a test pins it.

**Reversing it** means switching the columns to `timestamptz` and dropping the
cast, which is a defensible thing for a fork to do. It is not the default here
because `timestamptz` behaviour varies by driver and the point of the cast is
that the guarantee does not depend on one.

---

## 0022 — The address token carries the caller's words as well as the geocoder's

The sealed address token holds two strings that look redundant and are not.

`spoken` is the tidy readback — "3 Hanbury Street, London, E1 6QR" — which is
what the agent says out loud. `raw_spoken` is what the caller actually said,
including the part the geocoder discarded: the "second door past the chippy,
blue gate" that no coordinate will ever capture.

`Address::$raw_spoken_text` is meant to be that second one, kept forever,
because it is the only record of what a caller said when a delivery goes wrong.
Before this it stored the geocoder's rendering, which is the one thing already
recoverable from the other columns.

**Reversing it** is one key in the token payload and one line in the
order-creation controller.

---

## 0023 — Token expiry runs on Carbon's clock, not `time()`

`AddressToken` stamped and checked its TTL with `time()`, which no clock in the
application controls. The TTL was therefore unreachable from a test: travelling
an hour forward moved Carbon and left `time()` where it was, so an expired
token was still accepted and the test that found this passed for the wrong
reason in both directions.

Both ends now use `now()->getTimestamp()`. Identical in production, and the
expiry is something a test can actually watch fire.

The general form of this, worth stating once: a clock the test suite cannot
move is a branch the test suite cannot reach. Anything in this repo that
expires, times out, or schedules should read the time through Carbon.
