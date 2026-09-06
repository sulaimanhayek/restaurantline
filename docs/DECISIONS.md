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
