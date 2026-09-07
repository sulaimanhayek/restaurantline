<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery addresses.
 *
 * Two columns here do disproportionate work.
 *
 * `raw_spoken_text` is whatever the caller actually said, stored verbatim and
 * never overwritten. When a delivery goes to the wrong street, this is the only
 * record of what the system was given as opposed to what it decided. It is the
 * single most useful debugging column in the schema and costs nothing to keep.
 *
 * `verified_at` is an enforcement mechanism, not a status flag. The order
 * creation endpoint rejects any delivery order whose address has a null
 * `verified_at`, which makes "the agent must read the address back and get a
 * yes" a property of the system rather than a hope about the prompt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->text('raw_spoken_text')->nullable();

            $table->string('formatted_address')->nullable();

            $table->string('line_1')->nullable();
            $table->string('line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('postcode')->nullable();
            $table->string('country', 2)->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // 0.0–1.0 as reported by the geocoder, normalised across providers.
            $table->decimal('geocode_confidence', 4, 3)->nullable();

            // google | fake | manual — see App\Enums\GeocodeProvider.
            $table->string('geocode_provider')->nullable();

            // The provider's own identifier, so a re-lookup is exact.
            $table->string('place_id')->nullable();

            // Set only when the caller has heard the address read back and
            // agreed to it. Null blocks order creation for delivery.
            $table->timestamp('verified_at')->nullable();

            // Cached straight-line distance from the restaurant, in metres.
            $table->unsignedInteger('distance_metres')->nullable();

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['customer_id', 'is_default']);
            $table->index(['restaurant_id', 'postcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
