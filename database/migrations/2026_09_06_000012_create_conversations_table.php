<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per call, whether or not it produced an order.
 *
 * The rows without orders are the point. A conversation table that only
 * recorded successes would tell you the agent works; this one tells you where
 * it doesn't — which item it keeps mishearing, which postcodes it fails to
 * resolve, how often callers give up and ask for a human.
 *
 * Created when the post-call webhook arrives, or earlier if the agent has
 * already called a tool carrying its conversation id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            // ElevenLabs' identifier for the call. Unique, and the key every
            // agent tool call carries, which is what makes order creation
            // idempotent across retries.
            $table->string('elevenlabs_conversation_id')->unique();
            $table->string('elevenlabs_agent_id')->nullable();

            $table->string('direction')->default('inbound');

            // E.164 where available. Withheld numbers exist, hence nullable.
            $table->string('caller_number')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            // Full turn-by-turn transcript as delivered by the webhook.
            $table->jsonb('transcript')->nullable();

            // ElevenLabs' own analysis block: evaluation criteria results,
            // data collection results, call summary.
            $table->jsonb('analysis')->nullable();

            // Recording. `audio_url` is ElevenLabs' link; `audio_path` is set
            // once we have stored a copy on our own disk, because their links
            // expire and a transcript without audio is much harder to review.
            $table->string('audio_url', 1024)->nullable();
            $table->string('audio_path')->nullable();

            $table->string('outcome')->default('pending');

            // Set when the outcome warrants a human look — see
            // ConversationOutcome::warrantingReview(). This is what the
            // dashboard's "flagged" filter reads.
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            // Cost of the call in the restaurant's currency minor units, plus
            // the raw ElevenLabs credit figure, since the two do not convert
            // cleanly and you will want both when estimating cost per order.
            $table->bigInteger('cost')->nullable();
            $table->unsignedInteger('cost_credits')->nullable();

            // Whatever the webhook sent, kept verbatim. New fields appear in
            // this payload as the platform evolves; this means a schema change
            // is never required to avoid losing them.
            $table->jsonb('raw_payload')->nullable();

            $table->timestamps();

            $table->index(['restaurant_id', 'outcome']);
            $table->index(['restaurant_id', 'needs_review']);
            $table->index(['restaurant_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
