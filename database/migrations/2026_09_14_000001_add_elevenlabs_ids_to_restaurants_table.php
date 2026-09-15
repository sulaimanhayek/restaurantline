<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of what provisioning has to remember.
 *
 * `elevenlabs_agent_id` and `elevenlabs_tool_ids` were on the table from the
 * start, back when the plan was that everything else — the workspace secret,
 * the post-call webhook, the phone number — was a step a person did by hand in
 * the dashboard. Re-reading the API against its OpenAPI document turned all
 * three into endpoints, which makes them all things `kitchenline:provision`
 * creates and therefore things it has to recognise on the second run.
 *
 * An id here is the difference between provisioning being idempotent and
 * provisioning quietly making a second copy of everything every time it runs.
 *
 * @see docs/DECISIONS.md #0040, #0041
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            // The workspace secret holding the `Authorization` header value the
            // nine tools send. One secret, referenced by id nine times, so
            // rotating AGENT_API_TOKEN never touches a tool definition.
            $table->string('elevenlabs_secret_id')->nullable()->after('elevenlabs_tool_ids');

            // The post-call webhook. Its signing secret is not here and never
            // will be: ElevenLabs returns it once, at creation, and it belongs
            // in the environment as ELEVENLABS_WEBHOOK_SECRET.
            $table->string('elevenlabs_webhook_id')->nullable()->after('elevenlabs_secret_id');

            // The imported phone number resource, which is what actually points
            // a ringing telephone at the agent.
            $table->string('elevenlabs_phone_number_id')->nullable()->after('elevenlabs_webhook_id');

            /*
             * Dropped rather than left empty. It was meant to hold the Twilio
             * side of the phone number, but importing a number needs the
             * account SID and the number itself, and what comes back is
             * ElevenLabs' own id — nothing in this application ever learns a
             * per-number Twilio SID, so nothing could ever have filled it in.
             */
            $table->dropColumn('twilio_phone_number_sid');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropColumn([
                'elevenlabs_secret_id',
                'elevenlabs_webhook_id',
                'elevenlabs_phone_number_id',
            ]);

            $table->string('twilio_phone_number_sid')->nullable();
        });
    }
};
