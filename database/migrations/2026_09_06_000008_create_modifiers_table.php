<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual modifier options.
 *
 * `kind` is what makes sizes, add-ons, removals and swaps share one code path.
 * "No onions" is an ordinary row here with kind = removal, price_delta = 0 and
 * its own spoken aliases, so the matcher, the pricing service, the order
 * snapshot and the kitchen ticket all handle it without special cases.
 * See docs/DECISIONS.md #0004.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');

            // Signed minor units. Negative is legitimate — a smaller size, or a
            // discount for declining a component.
            $table->bigInteger('price_delta')->default(0);

            // option | addon | removal | swap — see App\Enums\ModifierKind.
            $table->string('kind')->default('option');

            // Same role as MenuItem::spoken_aliases, and just as important.
            // A removal without aliases is a removal the caller cannot ask for.
            $table->jsonb('spoken_aliases')->nullable();

            $table->boolean('is_available')->default(true);

            // Pre-ticked when the item is added, e.g. a default sauce the
            // caller can decline.
            $table->boolean('is_default')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['modifier_group_id', 'slug']);
            $table->index(['restaurant_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifiers');
    }
};
