<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distance-banded delivery fees.
 *
 * Bands are evaluated in ascending `up_to_metres` order; the first band whose
 * upper bound covers the delivery distance wins. A band with a null
 * `up_to_metres` is the catch-all and must be last.
 *
 * `free_over_subtotal` waives that band's fee once the basket is large enough,
 * which is by far the most common promotion a small restaurant runs.
 *
 * All of this is resolved by PricingService. Nothing else is permitted to
 * compute a delivery fee, and the agent is never told how the bands work — it
 * asks for a quote and reads back the number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_fee_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            // Upper bound of this band, inclusive. Null means "and beyond".
            $table->unsignedInteger('up_to_metres')->nullable();

            // Minor units.
            $table->bigInteger('fee');

            // Minor units. Null means the fee always applies in this band.
            $table->bigInteger('free_over_subtotal')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['restaurant_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_fee_rules');
    }
};
