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

### Post-call payload fields, verified 2026-09-07

The envelope was recorded above on 2026-09-06; the fields inside `data` were
checked against the live documentation a day later, while writing the job that
reads them. Several are not where a reasonable person would guess, and a reader
that guesses wrong fails silently — every column involved is nullable, so a
wrong field name costs data rather than an exception.

`post_call_transcription.data`:

- `conversation_id`, `agent_id`, `agent_name`, `status`
- `transcript[]` — per turn: `role`, `message`, `tool_calls`, `tool_results`,
  `time_in_call_secs`, `conversation_turn_metrics`, `feedback`
- `metadata` — `start_time_unix_secs`, `call_duration_secs`, `cost`,
  `termination_reason`, `authorization_method`, `charging`, `deletion_settings`
- `analysis` — `call_successful`, `transcript_summary`,
  `evaluation_criteria_results`, `data_collection_results`
- `conversation_initiation_client_data` — including `dynamic_variables`
- `has_audio`, `has_user_audio`, `has_response_audio`

`post_call_audio.data` is `agent_id`, `conversation_id` and `full_audio`, a
base64-encoded MP3. `call_initiation_failure.data` is `agent_id`,
`conversation_id`, `failure_reason` (`busy`, `no-answer` or `unknown`) and a
`metadata` block carrying the provider's own payload.

Three that cost time:

1. **The caller's number is not a top-level field.** It arrives as the
   `system__caller_id` dynamic variable, nested inside
   `conversation_initiation_client_data.dynamic_variables`. The siblings are
   `system__called_number`, `system__conversation_id`, `system__agent_id`,
   `system__call_duration_secs` and `system__call_sid`.
2. **The summary is `analysis.transcript_summary`**, not `call_summary`.
3. **Cost is `metadata.cost`**, in credits, not currency.

There is no end timestamp. `ended_at` is computed from
`start_time_unix_secs + call_duration_secs`, which is why a payload missing
either leaves it null rather than guessing.

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

---

## 0024 — The post-call webhook fails closed, answers fast, and explains nothing

Three decisions about the webhook boundary, recorded together because they only
make sense as a set.

**Fail closed when the secret is unset.** `VerifyElevenLabsSignature` rejects
every request when `ELEVENLABS_WEBHOOK_SECRET` is empty, rather than skipping
verification. The alternative — treating an unset secret as "verification off"
— is the shape of most webhook breaches: it works perfectly in development,
survives review because nobody reads the disabled branch, and ships as an open
endpoint that writes to the database. A forker who has not set the secret yet
gets a 401 and a log line saying why, which is a much better first hour than an
endpoint anyone can post to.

Consequence, stated plainly: the webhook does not work until the secret is set.
That is the intent. The signature is the only access control this route has —
there is no session, no token, no IP allowlist.

**Answer in milliseconds, work in a queue.** The controller validates the
envelope, dispatches `ProcessElevenLabsWebhook`, and returns 200. Nothing else.
A webhook sender reads a slow response as a failed delivery and sends the
payload again, so any work done inline is work that buys duplicates of itself
— and `post_call_audio` carries a whole base64 MP3, which is not a body to
process while a socket waits.

A signed payload we cannot parse still gets a 200, with `handled: false` in the
body. It came from ElevenLabs, so it is a shape change rather than an attack,
and retrying a shape change just delivers the same unreadable thing until the
sender gives up. The log line is the actionable part.

**The 401 discloses nothing.** The refusal body is `{"ok": false}` whether the
signature was absent, malformed, stale or wrong. The reason goes to the log,
where the operator can read it and an attacker cannot. Distinguishing "bad
timestamp" from "bad signature" in the response tells someone probing the
endpoint exactly which half of the header to keep working on.

The timestamp is checked *before* the hash is computed, and is bound into the
hashed string — a captured header with a fresh `t=` fails, because the `t` is
part of what was signed. There is a test for precisely that, since it is the
first thing anyone with a captured request would try.

**Reversing any of this** is a few lines in one middleware and one controller.
The fail-closed default is the one worth arguing about, and the argument should
happen before it is changed, not after.

---

## 0025 — The dashboard is one Filament panel at `/admin`, and every user model implements `FilamentUser`

Two audiences share this panel and want opposite things. The person who forked
this repo wants every field on screen, because they are wiring it to a client.
The person answering the phone at 8pm on a Friday wants tonight's orders and
nothing else. Where those conflict the second wins: they use it every day.

Concretely that means orders and calls sit at the top of the navigation on
their own, and everything a restaurant touches once a week — the menu, modifier
groups, customers, settings — is filed behind a heading. Primary colour is
amber, which reads as "kitchen" rather than "SaaS" and, more usefully, stays
legible under warm lighting on a grease-filmed screen.

**One panel, not two.** A separate operator panel is the obvious next step and
deliberately not taken yet: there is one restaurant, no roles, and a second
panel would double the number of places a resource has to be registered before
anyone has asked for it. The kitchen display in Phase 6 is a page in this panel,
not a panel of its own.

**`User` implements `FilamentUser`.** This is not decoration. Filament's panel
middleware falls back to `config('app.env') === 'local'` for a user model that
does not implement the contract — safe by default, and it means the first
deploy locks out the person who just deployed it, with a bare 403 and nothing
in the log. `canAccessPanel()` checks the tenant rather than a role, so that on
the day this install serves two restaurants an account stamped with the wrong
one cannot read the other's orders and call recordings. Add the role check
there when you add roles.

**Reversal cost:** low. One provider and one method.

---

## 0026 — Enums carry their own Filament labels, colours and icons

All eight domain enums implement `HasLabel`, `HasColor` and `HasIcon`. A status
badge is then `TextColumn::make('status')->badge()` with no mapping array
anywhere, and it looks the same in the orders table, the order infolist, the
call review screen and the kitchen display in Phase 6.

The alternative is a `match` in each resource. It works, and it drifts: the day
`OrderStatus::Failed` is added, four of the five screens get the new case and
the fifth silently renders a grey badge with a raw `failed` in it. Putting the
presentation on the enum means adding a case is one edit and the compiler-ish
part of it — a `match` with no default — tells you when you have missed
something.

The cost is a domain enum that knows about a UI library. That is a real
concession and worth naming: `App\Enums\OrderStatus` now imports from
`Filament\Support\Contracts`. It is accepted because the alternative spreads the
same coupling across five files instead of one, and because these enums are
this application's vocabulary rather than a reusable package's.

**Reversal cost:** low, and mechanical — delete three methods per enum and add a
mapping wherever a badge appears.

---

## 0027 — The call review screen puts the transcript beside what it produced

The single most valuable screen in this repo for the person tuning an agent.
It exists because the question you actually have is never "what did the caller
say" or "what did the system do" — it is "what did the caller say that made the
system do *that*", and answering it by opening two tabs and scrolling both is
how agent tuning stops happening.

So: the transcript on the left, and on the right the order it produced —
lines, totals, address, fulfilment — or an explicit statement that it produced
none. Above both, a callout when the call is flagged, carrying the reason
someone wrote. Below, collapsed, whatever ElevenLabs' own analysis made of it,
which is useful and is not the primary evidence.

Audio and transcript are one component rather than two. Clicking a turn's
timestamp seeks the player, because reading a line and then hearing it is the
whole reason anyone opens this screen — the transcript will not tell you the
caller was three words into their postcode when the agent cut them off.

The list this screen hangs off opens **filtered to flagged calls**. That is a
deliberate default, not an oversight: the point of a flag is that somebody sees
it, and a list that opens on all four hundred calls of the week buries it on
page nine. The filter is one click away from off.

**Reversal cost:** low. One infolist and one Blade view.

---

## 0028 — Restaurant settings is a Page, not a Resource

There is one restaurant. A Filament resource for it would give a list page with
a single row, a create button that must be disabled, and a delete action that
must be removed — three pieces of scaffolding whose only job is to hide the
fact that the model underneath is a singleton.

`RestaurantSettings` is a `Filament\Pages\Page` that loads `Restaurant::current()`
in `mount()` and saves it back. Four sections in the order someone setting up a
client fills them in: the restaurant, where you are, taking orders, the agent.

The page defines `content()` returning an embedded schema rather than pointing
at a Blade view, which is the v4 pattern Filament's own `EditTenantProfile`
uses. Worth writing down because it is not obvious from the outside that a
`Page` subclass needs no view file at all.

**When multi-tenancy arrives** this becomes either a resource or a tenant
profile page, and the form components move across unchanged. The thing that
does not survive is `Restaurant::current()` in `mount()`, which is one line and
is already the single place that resolution happens (see #0005).

**Reversal cost:** low.

---

## 0029 — Custom Blade inside the panel styles itself with Filament's CSS variables

Filament v4 ships a **prebuilt** stylesheet containing only the utilities its
own components use. A Tailwind class written in a custom view — `flex`,
`max-w-md`, `bg-gray-100` — is not in that file, and the browser silently does
nothing with it. Nothing errors. The view renders, wrong, and looks like a
layout bug.

The documented fix is a compiled custom theme, which means `npm install && npm
run build` between a fresh clone and a dashboard that looks right. This repo is
optimised for a developer's first hour, and a build step to make the review
screen legible is exactly the kind of step it is trying not to have.

So `review.blade.php` carries a scoped `<style>` block using `rl-`-prefixed
class names and Filament's own custom properties — `--primary-50`,
`--gray-100`, and the rest — with a `.dark` override block. It inherits the
panel's colour scheme, including a user's amber, without compiling anything.

This applies to every custom view added to the panel later. If you do add a
compiled theme, these blocks can be replaced with utilities and nothing else
changes.

**Reversal cost:** low, and the reversal is additive — a compiled theme does not
break the scoped styles.

---

## 0030 — Compose gives the container no `env_file`; `.env` is authoritative inside it

`env_file: .env` copies every key in the file into the container's real
environment at creation time. Laravel's environment repository is immutable and
reads `$_SERVER` **before** it reads `.env`, and it treats "set but empty" as
set. Both halves are reasonable. Together they are a trap.

A fresh clone's `.env.example` has `APP_KEY=`. The container is therefore born
with an empty `APP_KEY` in its real environment, which permanently shadows the
key the entrypoint generates a second later. `php artisan key:generate` writes a
perfectly good key to `.env` and every process in the container keeps reading
the blank one.

The same mechanism was worse in the test suite. PHPUnit's `<env>` elements only
apply when the variable is not already set, so `DB_DATABASE=restaurantline`
arrived from the container and **the Pest suite ran `RefreshDatabase` against
the development database** — dropping the demo data on every run, which for a
while looked like a flaky seeder.

Three changes, all of which are the same decision:

- No `env_file:` on the app services. `.env` is mounted with the source tree, so
  Laravel reads it directly. Only `DB_HOST` and `REDIS_HOST` are passed through
  `environment:`, because those are the two values that genuinely differ inside
  the Docker network.
- Every `<env>` in `phpunit.xml` carries `force="true"`, including an explicit
  `APP_KEY`. A test configuration that the ambient environment can overrule is
  not a test configuration.
- The entrypoint unsets any of `APP_KEY APP_URL DB_DATABASE DB_USERNAME
  DB_PASSWORD` that arrive blank, as belt and braces for a container started
  some other way.

The test `APP_KEY` is hard-coded and is not a secret: it encrypts nothing that
outlives a test run, and hard-coding it means `composer check` works on a clone
whose `.env` has no key yet.

**Reversal cost:** low, and inadvisable.

---

## 0031 — Filament state casts hand closures floats, so the money closures take `mixed`

`TextInput::numeric()` installs a `NumberStateCast`, which runs `floatval()`
over the field's state. `dehydrateStateUsing` is therefore handed a **float**.

`MoneyInput`'s dehydrator was first written with the parameter typed
`int|string|null`, on the reasonable assumption that a wrong type in a
`strict_types=1` file would raise a `TypeError`. It does not. `strict_types`
applies at the **call site**, and the call site is Filament's, which is not
strict. PHP coerced, preferred `int`, and `2.99` became `2` — stored as 200
pence.

Nothing threw. The form saved. The delivery fee was quietly wrong by a pound,
which is the sort of bug that surfaces in an accounts reconciliation three
months later.

Both closures now take `mixed`. The rule generalises: **a closure handed to a
vendor library gets no useful protection from a narrow union**, and a narrow
union there is worse than none, because it silently converts instead of
failing. `MoneyInputTest` pins the round trip with the awkward values —
`12.50`, which `(int) ($v * 100)` gets wrong on its own, and `0`.

**Reversal cost:** none. This is a bug fix with a note attached.

---

## 0032 — Call recordings are served by a controller; nothing in the dashboard deletes an order or a call

A recording is a customer saying their address and their phone number out loud.
It is the most sensitive thing this application stores and the only thing in it
that cannot be regenerated.

**They are not on a public disk.** `ELEVENLABS_AUDIO_DISK` defaults to `local`,
and the review screen's player points at
`/conversations/{conversation}/audio` — a controller behind the panel's own
`Authenticate` middleware, which checks the tenant before streaming and 404s
otherwise. A public disk would give every recording a guessable URL, and
guessable URLs for that content are a breach waiting for somebody to notice the
pattern. The response is streamed rather than downloaded so the player can seek
without the whole call being held in memory.

`Conversation::audioSource()` prefers the local copy over ElevenLabs' own URL,
because theirs expires: a review screen that plays for a week and then silently
stops is worse than one that never offered playback.

**Nothing deletes an order or a call.** `canDelete()` and `canDeleteAny()`
return false on both resources, overridden rather than left to a policy so that
a bulk action added to a table later is refused too.

An order is a financial record with a phone call behind it, and the thing an
operator actually wants when they reach for delete is `Cancelled` — which the
edit form offers, and which keeps the row, the reason and the link to the
transcript. A call is the evidence that settles a disputed order and the raw
material for fixing the agent's prompt; the dashboard offers "mark reviewed",
which is what someone reaching for delete usually means.

**Retention is a separate, deliberate job**, not a button next to a row. This
repo does not ship one yet, and a real deployment serving real customers needs
one: decide how long recordings are kept, write the command, schedule it, and
say so in the restaurant's privacy notice. That is a conversation with the
client, not a default this boilerplate should pick.

**Reversal cost:** low for the delete rules, higher for the disk — moving
recordings to a public bucket later means auditing every URL that has already
been shared.

---

## 0033 — The dashboard pays its static-analysis costs in code, not in `phpstan.neon`

The Filament panel arrived with 46 level-6 errors. Every one of them was fixed
in the code rather than by widening the ignore list, because the ignores that
already exist (#0010) are each pinned to a single unfixable library quirk, and a
list that grows every phase stops being a list of exceptions.

**Enum adapters declare what they return, not what the interface allows.**
`HasLabel::getLabel()` is `string|Htmlable|null` because Filament accepts all
three. Ours return a `string`, always, so they say `string`. Return types are
covariant in PHP, so narrowing is legal, and it means the analyser can see that
a badge label is never null without reading the body.

**The tenant-scoping trait is generic.** `Builder` is invariant in its model, so
`Builder<Order>` is not a `Builder<Model>` and a trait declaring the loose type
cannot satisfy a resource declaring the tight one. `ScopesToCurrentRestaurant`
now takes a `@template TModel`, and each resource states its model twice:
`@extends \Filament\Resources\Resource<Order>` on the class so the parent query
is typed, and `@use ScopesToCurrentRestaurant<Order>` on the `use` statement so
this trait is. The tag has to sit on the `use` statement — a class-level `@use`
is silently ignored — and `@extends` has to be fully qualified, because Pint's
`phpdoc_types` fixer lowercases a bare `Resource` into PHP's native `resource`
type. Both are noted in the files themselves; neither is guessable.

**Tests assert on properties, not on higher-order expectations.**
`expect($order)->status->toBe(...)` reads well and analyses to nothing: the
chain goes through `Expectation::__get()`, and PHPStan can only follow it if
`Pest\Expectation` is registered as a universal object crate — which switches
off checking for every property in every chain. The suite uses
`expect($order->status)->toBe(...)->and($order->cancellation_reason)->toBe(...)`
instead, which is barely longer and, with `checkModelProperties` on, means a
misspelled attribute is a failed build rather than a test that quietly asserts
against null.

The same rewrite removed several `->fresh()` calls in favour of `->refresh()`.
`fresh()` returns `?static` and models a real possibility — the row was deleted
underneath you — that a test asserting on the next line does not want to think
about. `refresh()` returns the model.

**Reversal cost:** low. Each of these is local to the file it appears in.

---

## 0034 — Status changes are stamped and announced in one place: the observer

`orders` carries `confirmed_at`, `accepted_at`, `ready_at`, `completed_at` and
`cancelled_at`. Before this phase only the first was ever filled in, by the
agent's confirm endpoint, and the rest were columns waiting for somebody to
build a report on them.

Status is set from at least four places already — the agent's confirm endpoint,
the dashboard's status dropdown, the kitchen display, and whatever a fork adds
next. Stamping at each call site means a `ready_at` that only some of them fill
in, which is worse than one nothing fills in: the average-prep-time report built
on it looks right and is wrong.

So `OrderStatus::timestampColumn()` says which column belongs to which status,
and `OrderObserver::updating()` sets it. `updating` rather than `updated` so the
column lands in the same `UPDATE` and the row is never readable in the state
where the status has moved and the timestamp has not. An explicitly supplied
value always wins, so the confirm endpoint and a fork backfilling history both
still work.

The same observer dispatches `OrderReceived` and `OrderStatusChanged`. **The
events carry an identifier, not a copy of the order** — `order_id`,
`order_number`, `status`, and the previous status where there is one. A payload
containing the caller's name, phone number and delivery address would be a
payload sitting in a Redis queue and travelling to every subscribed browser, and
the subscriber already has a database. The listener's job is to ask for a
re-render, which is what `$refresh` does.

Both events implement `ShouldDispatchAfterCommit`, so nothing announces an order
that a failed transaction then rolled back.

**Reversal cost:** low.

---

## 0035 — The kitchen display is three columns and one tap, and there is no undo

`/kitchen` is a full-page Livewire component on its own layout, not a Filament
page. A panel gives you a sidebar, a topbar, a user menu and a breadcrumb trail
— wasted pixels on a screen nobody navigates, and tap targets somebody's elbow
will eventually find.

**Three columns, not six.** The board shows New, Preparing and Ready.
`Confirmed` and `Accepted` share the first column, because the difference
between them is who agreed to the order and the kitchen does not care. Every
other status is off the board: `Draft` and `Confirming` have not been agreed to
by the caller yet, and putting one in front of a chef would have food cooked for
an order that does not exist.

**One tap per card**, labelled for what happens next — Start, Ready, Done —
rather than for the status it lands in. "Preparing" on a button reads as a
description of the card you are looking at; "Start" reads as an instruction.

**There is no undo.** Undo on a touchscreen means a second control next to the
first, and the tap that needs undoing is nearly always the one that was aimed at
the control beside it. Fixing a mistake is a job for the dashboard, where there
is a keyboard and a mouse and a full status dropdown.

**A tap that cannot be honoured does nothing and says nothing.** A missing
order, another restaurant's order, an order with nowhere left to go: the
re-render that follows shows the board as it actually is, which is the answer to
all three, and an error toast on a wall-mounted screen is noise nobody is
positioned to act on.

`restaurant_id` is `#[Locked]`. It is half of the websocket channel name and the
tenancy key every query filters by, and a property the browser can send back is
a property the browser can change.

**The component re-checks authorisation on every request, in `boot()`.** The
route sits behind Filament's `Authenticate` middleware, but Livewire's own
`/livewire/update` endpoint does not, and it cannot simply be moved behind auth
because the login screen is itself a Livewire component. Without the `boot()`
check, a snapshot signed while signed in would keep working after signing out.

**Reversal cost:** low.

---

## 0036 — The kitchen screen needs no build step, and polls even when the socket is up

Two decisions, both aimed squarely at the first hour with this repo.

**No npm required.** `layouts/kitchen.blade.php` carries its own stylesheet —
about ninety lines, no Tailwind — and the behaviour comes from Livewire's own
bundled JS, which `@livewireScripts` serves from the vendor directory. `@vite`
is called only when `App\Support\CompiledAssets::exist()` says there is
something to include, because `@vite` throws when the manifest is missing and a
fresh clone would otherwise answer `/kitchen` with a white screen and a stack
trace. Echo and Reverb are an upgrade layered on top, not a requirement: clone,
`docker compose up`, open the page, and it works.

`CompiledAssets` checks both `isRunningHot()` and `manifestHash()` because
neither implies the other — the dev server writes a hot file and no manifest, a
production build writes a manifest and no hot file.

**`wire:poll` runs the whole time.** It is not a fallback that switches on when
the websocket drops. A kitchen that has stopped receiving orders cannot tell
that apart from a quiet evening, and by the time anyone works it out the food is
already late. Fifteen seconds of stale is recoverable; an evening of it is not.
`KITCHEN_POLL_SECONDS` tunes it and the component floors it at three.

The header says which of the two is carrying the screen — Live, Reconnecting, or
Polling — so the answer to "is this thing working?" is on the wall rather than
in a browser console nobody is going to open.

**Reversal cost:** low. Deleting the inline stylesheet in favour of a built one
is a one-file change, and the gate degrades to a no-op once a build always
exists.


## 0037 — The restaurant declares which payment methods it takes; the agent asks only when there are two

Restaurants get `accepts_card_link` and `accepts_cash`. If one is on, the
application picks it and the agent is never told the other exists. If both are
on, the agent asks, and `POST /api/agent/orders/{number}/confirm` takes an
optional `payment_method` field which is required only in that case —
`ConfirmOrderRequest::rules()` reads the restaurant to decide.

The alternatives were a global config constant (wrong the moment a second
restaurant is onboarded, and the schema is multi-tenant from day one), always
asking (a question with one possible answer, asked on every call, to a caller
who is standing in a kitchen), or letting the model decide (a cash-only shop
texting Stripe links because the prompt drifted).

Three consequences worth stating, because each is a rule some other part of the
code depends on:

- When the restaurant offers one method, whatever the agent sent is **discarded**
  rather than validated. It was never told about the other method, so anything
  it sends about it is noise. `ConfirmOrderRequest::paymentMethod()` is the only
  thing that decides.
- A restaurant with neither flag set falls back to cash. That is a
  misconfiguration, not a supported setting, and the order is real and the
  caller is waiting — somebody can take money at the door, and nobody can take
  money for an order that was never confirmed.
- There is no third case. `PaymentMethod` has two arms and the absence of a
  "card taken by phone" arm is deliberate and load-bearing; see
  `app/Services/Payments/README.md`.

Payment is settled at **confirmation**, not at creation. The order exists in
`confirming` while the total is read back, and the method is part of the yes.

**Reversal cost:** low for the flags, higher for the endpoint. Adding a method
is an enum case, a column and a `match` arm. Making `payment_method`
unconditionally required would be a breaking change to a tool contract that is
already in an agent's configuration.


## 0038 — The confirmation SMS and the payment link run in one job, after the response, and are allowed to fail

`SendOrderConfirmation` creates the payment link, texts the customer, and writes
the message down. It is dispatched with `dispatchAfterResponse()` rather than
`dispatch()`: running one restaurant on `QUEUE_CONNECTION=sync` is a perfectly
reasonable choice, and a caller still holding the phone should not be listening
to silence through a Stripe round trip and a Twilio one.

The failure handling is the substance of this decision, and it is deliberately
different in each direction:

- **A text that will not send is recorded, not thrown.** A mistyped number or a
  landline is a normal operational event and the caller has already hung up. The
  `sms_messages` row carries the provider's own error so the person in the
  dashboard can see why and press send again. The row is written as `queued`
  *before* the send and updated after, so a worker killed mid-request leaves "we
  do not know" rather than "we never tried".
- **A link that will not create is thrown, retried, and then abandoned in favour
  of cash.** Stripe having a bad thirty seconds is worth three attempts over two
  minutes. Anything still failing after that is a configuration problem retrying
  will not fix, and an order left unpaid with no link is the one outcome nobody
  can act on — the customer has nothing to tap and the driver has not been told
  to collect. Cash is a worse margin and a completed order.

`payment_status` becomes `link_sent` only when the text carrying the link
actually went out, not when Stripe returned a URL. A customer chasing a link
they never received is asking about the message.

Nothing in this job decides whether the kitchen cooks the food. The order is
already `confirmed` in the database before it runs, which is what makes all of
the above safe.

The dashboard's "text the link again" button is the manual counterpart, and it
**resends the link the order already has** rather than creating a new one. Two
live Checkout sessions for one order is two ways to pay for one dinner, and the
webhook that marks it paid quotes only one of them. It is hidden on an order
that has been paid, and it reads back the row the dispatcher wrote rather than
reporting success on a text the provider refused.

**Reversal cost:** low. Splitting it into two jobs, or moving it inline, is a
change to one file.


## 0039 — Each webhook route declares its own verifier, and the Stripe one accepts several signatures

`routes/webhooks.php` is registered with `Route::group([], …)` and each route
names its own middleware. Putting `VerifyElevenLabsSignature` on the group — the
obvious thing, and what this repo did until Stripe arrived — would reject every
genuine payment notification with a 401, because the two senders do not share a
secret. The comment in `bootstrap/app.php` says so, since the group form is what
somebody adding a third webhook will reach for.

Both verifiers are hand-written rather than delegated to an SDK. The check is
thirty lines, the repo now contains two near-identical versions of it, and a
forker adding a fourth learns the pattern by reading them in a way that a call
into `Webhook::constructEvent` does not teach. Both hash the **raw** body, both
use `hash_equals`, both enforce a timestamp tolerance, and both **fail closed**
when their secret is unset — an install with no secret must not be accepting
payment notifications from strangers.

The one thing the Stripe verifier does that its twin does not is collect *every*
`v1` value from the header. Stripe signs with all active endpoint secrets during
a rotation, and a parser that read only the first would pass every test written
against a single secret and then reject half the traffic for the length of the
rollover — a payment endpoint failing silently for hours.

`StripeWebhookController` runs inline rather than queueing. The work is one
indexed lookup and an update, well inside Stripe's timeout, and doing it inline
means the order is already green by the time the customer's browser lands back
on the return page. It answers 200 to an event it does not handle or cannot
parse: the signature proved it came from Stripe, and a non-2xx would start a
retry storm for an event this install can never do anything with.

This endpoint is the only way an order reaches `paid`. Nothing in the voice flow,
the agent tools or the dashboard's normal path can set it, because none of them
knows whether money moved.

**Reversal cost:** low. Swapping either verifier for an SDK call is one file.
