<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant root.
 *
 * restaurantline runs a single restaurant, but every tenant-scoped table below
 * carries a `restaurant_id` from day one so that adding real multi-tenancy
 * later is a matter of introducing a resolver and a global scope rather than
 * rewriting the schema. See docs/DECISIONS.md #0005.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurants', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();

            // IANA identifier, e.g. "Europe/London". All opening-hours logic
            // uses this rather than the application timezone, because the
            // question "are we open?" is only ever meaningful locally.
            $table->string('timezone')->default('UTC');

            // ISO 4217. Every monetary column in this schema is an integer in
            // this currency's minor unit. See docs/DECISIONS.md #0001.
            $table->string('currency', 3)->default('GBP');

            // The number customers call. E.164.
            $table->string('phone_number')->nullable();

            // Where the agent transfers a caller who asks for a human. E.164.
            // Surfaced by the /api/agent/escalate tool.
            $table->string('transfer_phone_number')->nullable();

            $table->string('email')->nullable();

            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('postcode')->nullable();
            $table->string('country', 2)->nullable();

            // Plain lat/lon rather than PostGIS. Delivery distances here are
            // small enough that haversine on an indexed bounding box is both
            // accurate enough and one less extension for a forker to install.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Straight-line metres. Deliberately not road distance: road
            // routing costs an API call per address validation, and for a
            // single-restaurant delivery radius the difference rarely changes
            // the yes/no answer. Swap the distance calculator if it does.
            $table->unsignedInteger('delivery_radius_metres')->default(5000);

            // Minor units. Orders below this are still quotable — the agent
            // warns the caller rather than refusing outright.
            $table->bigInteger('minimum_order_value')->default(0);

            // Fallback used when no delivery_fee_rules band matches.
            $table->bigInteger('base_delivery_fee')->default(0);

            // Quoted preparation time, in minutes, used to estimate when an
            // order will be ready. Collection is usually quicker because there
            // is no driver leg.
            $table->unsignedSmallInteger('collection_prep_minutes')->default(20);
            $table->unsignedSmallInteger('delivery_prep_minutes')->default(45);

            // Operator kill switch. When false the agent still answers, still
            // quotes hours, and still escalates — it just will not create
            // orders. Useful when the kitchen is swamped.
            $table->boolean('is_accepting_orders')->default(true);

            // Free text injected into the agent's system prompt, so the
            // operator can set tone without editing a Blade template.
            $table->text('agent_tone_of_voice')->nullable();
            $table->text('agent_greeting')->nullable();

            // --- ElevenLabs wiring -------------------------------------------
            // Populated by `php artisan kitchenline:provision`.
            $table->string('elevenlabs_agent_id')->nullable();

            // Map of tool name => ElevenLabs tool id. Tools are standalone API
            // resources that agents reference by id, so provisioning has to
            // remember which ids it created or it would duplicate all nine on
            // every run. See docs/DECISIONS.md #0006.
            $table->jsonb('elevenlabs_tool_ids')->nullable();

            $table->timestamp('provisioned_at')->nullable();

            // The Twilio number resource backing the agent's phone line.
            // Attaching it is a manual dashboard step today; stored here so the
            // dashboard can show which number is live.
            $table->string('twilio_phone_number_sid')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurants');
    }
};
