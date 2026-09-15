<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| restaurantline
|--------------------------------------------------------------------------
|
| One config file for the whole application, so there is one place to look.
| Every key here is backed by a variable documented inline in .env.example,
| and every outbound integration defaults to a fake driver that makes no
| network calls and needs no credentials.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Bootstrapping
    |----------------------------------------------------------------------
    */

    // Seed the demo restaurant and sample menu when the container starts and
    // the database is empty. Turn off once a real menu is loaded.
    'seed_on_boot' => (bool) env('RESTAURANTLINE_SEED_ON_BOOT', true),

    /*
    |----------------------------------------------------------------------
    | Agent tool endpoints
    |----------------------------------------------------------------------
    |
    | The tools under /api/agent are authenticated with a static bearer token.
    | `kitchenline:provision` uploads it once as an ElevenLabs workspace secret
    | and points all nine tool definitions at it by id — see DECISIONS #0041.
    |
    */

    'agent' => [
        'token' => env('AGENT_API_TOKEN'),
        'rate_limit_per_minute' => (int) env('AGENT_RATE_LIMIT_PER_MINUTE', 120),

        /*
         * How long a sealed address from /address/validate stays usable.
         *
         * It only has to outlive one phone call. An hour is generous for that
         * and short enough that a token scraped from a log is worthless by the
         * time anyone reads it.
         */
        'address_token_ttl' => (int) env('AGENT_ADDRESS_TOKEN_TTL', 3600),
    ],

    /*
    |----------------------------------------------------------------------
    | Menu matching
    |----------------------------------------------------------------------
    |
    | How forgiving the matcher is when a caller says something that is not
    | quite an item name. Both numbers are trigram similarity scores in the
    | range 0.0-1.0.
    |
    */

    'menu' => [
        // Below this, a candidate is not offered at all. Too low and the agent
        // confidently reads back the wrong dish; too high and it says "sorry,
        // I didn't catch that" to people who spoke perfectly clearly.
        'minimum_confidence' => (float) env('MENU_MATCH_MINIMUM_CONFIDENCE', 0.3),

        // Above this, the agent may proceed without checking.
        'confident_threshold' => (float) env('MENU_MATCH_CONFIDENT_THRESHOLD', 0.62),

        // If the second-best candidate is within this margin of the best, the
        // match is ambiguous and the agent must ask which one they meant.
        'ambiguity_margin' => (float) env('MENU_MATCH_AMBIGUITY_MARGIN', 0.08),

        // Candidates returned to the agent for one query. More than a handful
        // is unreadable aloud.
        'max_results' => (int) env('MENU_MATCH_MAX_RESULTS', 5),
    ],

    /*
    |----------------------------------------------------------------------
    | Pricing
    |----------------------------------------------------------------------
    */

    'pricing' => [
        // Whether the restaurant's minimum order value applies to collection
        // orders as well as delivery. Almost nowhere charges one for
        // collection, so this is off. See docs/DECISIONS.md #0011.
        'minimum_applies_to_collection' => (bool) env('PRICING_MINIMUM_APPLIES_TO_COLLECTION', false),
    ],

    /*
    |----------------------------------------------------------------------
    | Geocoding
    |----------------------------------------------------------------------
    */

    'geocoder' => [
        // fake | google
        'driver' => env('GEOCODER_DRIVER', 'fake'),

        'google' => [
            'key' => env('GOOGLE_MAPS_API_KEY'),
            'endpoint' => 'https://maps.googleapis.com/maps/api/geocode/json',
            'timeout' => (int) env('GEOCODER_TIMEOUT', 8),
        ],

        // ISO 3166-1 alpha-2, or null for no bias.
        'region_bias' => env('GEOCODER_REGION_BIAS', 'GB') ?: null,

        // Candidates scoring below this are never offered to the caller.
        'minimum_confidence' => (float) env('GEOCODER_MIN_CONFIDENCE', 0.4),

        // How many candidates the agent may read out before giving up and
        // asking the caller to repeat the address.
        'max_candidates' => (int) env('GEOCODER_MAX_CANDIDATES', 3),

        // Above this, the agent may read one address back and take a yes.
        'confident_threshold' => (float) env('GEOCODER_CONFIDENT_THRESHOLD', 0.8),

        // Two candidates this close together are not distinguishable; the agent
        // has to ask which one rather than choose.
        'ambiguity_margin' => (float) env('GEOCODER_AMBIGUITY_MARGIN', 0.05),
    ],

    /*
    |----------------------------------------------------------------------
    | ElevenLabs
    |----------------------------------------------------------------------
    */

    'elevenlabs' => [
        // fake | api
        'driver' => env('ELEVENLABS_DRIVER', 'fake'),
        'api_key' => env('ELEVENLABS_API_KEY'),
        'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io'),
        'llm' => env('ELEVENLABS_AGENT_LLM', 'gpt-4o-mini'),
        'voice_id' => env('ELEVENLABS_VOICE_ID'),

        // Flash, because on a phone call latency is the experience. Any of the
        // eleven_* conversational models is valid here.
        'tts_model' => env('ELEVENLABS_TTS_MODEL', 'eleven_flash_v2_5'),

        // The agent's language, as an ISO 639-1 code.
        'language' => env('ELEVENLABS_AGENT_LANGUAGE', 'en'),

        // The ceiling on a single call, in seconds. Not a feature — a limit on
        // what one stuck conversation can cost.
        'max_call_seconds' => (int) env('ELEVENLABS_MAX_CALL_SECONDS', 600),
        'webhook_secret' => env('ELEVENLABS_WEBHOOK_SECRET'),
        'webhook_tolerance' => (int) env('ELEVENLABS_WEBHOOK_TOLERANCE', 1800),

        /*
         * Only the provisioning commands spend this. Generous, because a person
         * is watching the output and a timeout halfway through creating nine
         * tools is more annoying than a slow one.
         */
        'timeout' => (int) env('ELEVENLABS_TIMEOUT', 30),

        /*
         * A live eval is a whole conversation in one HTTP request — two models
         * taking turns, with a round trip to this application on every tool
         * call — so it gets its own budget rather than the one above.
         */
        'simulation_timeout' => (int) env('ELEVENLABS_SIMULATION_TIMEOUT', 300),

        /*
         * Where call recordings are written.
         *
         * Local by default so `docker compose up` works with no cloud account.
         * Point this at s3 before a real restaurant uses it: recordings are the
         * largest thing this application stores and the one thing it cannot
         * regenerate.
         */
        'audio_disk' => env('ELEVENLABS_AUDIO_DISK', 'local'),
    ],

    /*
    |----------------------------------------------------------------------
    | Twilio and SMS
    |----------------------------------------------------------------------
    |
    | Twilio is not used for voice — ElevenLabs owns the phone leg. These
    | credentials only send outbound SMS.
    |
    */

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM_NUMBER'),
    ],

    'sms' => [
        // log | twilio
        'driver' => env('SMS_DRIVER', 'log'),

        // Seconds. Short on purpose: this runs on a queue behind a call that
        // has already ended, and a worker blocked on a hung provider is a
        // worker not sending anybody else's confirmation.
        'timeout' => (int) env('SMS_TIMEOUT', 10),
    ],

    // Where a caller is transferred when they ask for a human. E.164.
    'transfer_number' => env('RESTAURANT_TRANSFER_NUMBER'),

    /*
    |----------------------------------------------------------------------
    | Kitchen display
    |----------------------------------------------------------------------
    |
    | The screen at /kitchen. It updates over websockets when Reverb is
    | running and the frontend assets have been built, and falls back to
    | polling otherwise — see docs/DECISIONS.md #0036.
    |
    */

    'kitchen' => [
        // How often the display re-queries when it cannot hear the websocket.
        // This is the only thing standing between a kitchen and a screen that
        // silently stopped updating, so it stays on even when Echo connects.
        'poll_seconds' => (int) env('KITCHEN_POLL_SECONDS', 15),

        // An order's timer turns amber once it has used this share of its
        // promised prep time, and red once it is past it. 75 means the chef
        // sees a warning with a quarter of the time left rather than at the
        // moment the customer is already waiting.
        'warn_at_percent' => (int) env('KITCHEN_WARN_AT_PERCENT', 75),
    ],

    /*
    |----------------------------------------------------------------------
    | Payments
    |----------------------------------------------------------------------
    |
    | Card details are never taken over the voice line and no code path here
    | accepts one. Payment is cash, or a link sent by SMS after the call.
    |
    */

    'payments' => [
        // fake | stripe
        'driver' => env('PAYMENT_DRIVER', 'fake'),

        'timeout' => (int) env('PAYMENT_TIMEOUT', 15),

        /*
         * The fake driver hands out links to a page in this application that
         * marks an order paid, which is exactly what you want on a laptop and
         * exactly what you do not want facing the internet. It refuses to run
         * in production unless this is set, and the only honest reason to set
         * it is a restaurant that takes no card payments at all.
         */
        'allow_fake_in_production' => (bool) env('PAYMENTS_ALLOW_FAKE_IN_PRODUCTION', false),

        'stripe' => [
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

            // How far out of step with Stripe's clock a webhook may be before
            // it is treated as a replay. Stripe's own recommendation.
            'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Evals
    |----------------------------------------------------------------------
    */

    'evals' => [
        // fake | live
        'mode' => env('EVAL_MODE', 'fake'),

        /*
         * The model that plays the caller in live mode. ElevenLabs runs it, so
         * this has to be a model their simulation endpoint accepts; it is not
         * the model the agent itself uses, which is set in AgentDefinition.
         */
        'caller_model' => env('EVAL_CALLER_MODEL', 'claude-sonnet-5'),

        /*
         * How many turns a simulated caller gets before the harness calls it a
         * day. A conversation that has not reached an order in twenty turns has
         * gone wrong in a way worth failing over, and the alternative is paying
         * for an agent and a caller to talk past each other indefinitely.
         */
        'turn_limit' => (int) env('EVAL_TURN_LIMIT', 20),

        /*
         * Where the scenarios live. A relative path is resolved against the
         * project root rather than against the working directory, so
         * `EVAL_SCENARIOS_PATH=evals/acme` means the same thing from a cron
         * entry, a deploy script and a shell sitting in app/.
         */
        'scenarios_path' => (static function (): string {
            $path = trim((string) env('EVAL_SCENARIOS_PATH', ''));

            return match (true) {
                $path === '' => base_path('evals/scenarios'),
                str_starts_with($path, DIRECTORY_SEPARATOR) => $path,
                default => base_path($path),
            };
        })(),
    ],

];
