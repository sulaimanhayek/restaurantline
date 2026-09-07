<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening hours, as two tables rather than a JSON blob.
 *
 * The recurring weekly pattern lives in `opening_hours`, one row per contiguous
 * serving window. A restaurant that closes between lunch and dinner simply has
 * two rows for that weekday — which is why there is no unique constraint on
 * (restaurant_id, day_of_week).
 *
 * `opening_hour_overrides` handles specific dates: bank holidays, a private
 * function, Christmas Day. An override for a date wins outright over the weekly
 * pattern for that date.
 *
 * Tables rather than JSON because /api/agent/hours has to answer "are you open
 * right now, and if not when do you next open?" on every call where the caller
 * asks, and that is a query, not a deserialisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            // 0 = Sunday … 6 = Saturday, matching Carbon's dayOfWeek.
            $table->unsignedTinyInteger('day_of_week');

            // Local wall-clock times in the restaurant's timezone.
            $table->time('opens_at');
            $table->time('closes_at');

            // True when the window runs past midnight ("22:00 – 02:00"). Kept
            // explicit rather than inferred, because inferring it from
            // closes_at < opens_at silently breaks a genuine 00:00 close.
            $table->boolean('closes_next_day')->default(false);

            // Optional label the agent can read out: "lunch", "dinner".
            $table->string('label')->nullable();

            $table->timestamps();

            $table->index(['restaurant_id', 'day_of_week']);
        });

        Schema::create('opening_hour_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            $table->date('date');

            // When is_closed is true the times are ignored and the restaurant
            // is shut for the whole day.
            $table->boolean('is_closed')->default(false);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('closes_next_day')->default(false);

            // Read out by the agent: "we're closed on the 25th for Christmas".
            $table->string('reason')->nullable();

            $table->timestamps();

            $table->unique(['restaurant_id', 'date', 'opens_at']);
            $table->index(['restaurant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_hour_overrides');
        Schema::dropIfExists('opening_hours');
    }
};
