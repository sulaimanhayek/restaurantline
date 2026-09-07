<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_category_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();

            // Minor units.
            $table->bigInteger('price');

            $table->string('sku')->nullable();

            // The operator's manual out-of-stock switch. Distinct from the
            // category availability window: this one means "we've run out",
            // that one means "we don't serve it at this hour".
            $table->boolean('is_available')->default(true);

            // The ways callers actually say this item.
            //
            // This field carries far more weight than its type suggests. It is
            // what /api/agent/menu/search matches against, and the difference
            // between an agent that works and one that frustrates callers is
            // usually a dozen well-chosen aliases here rather than anything
            // clever in the matcher. "Peri peri chicken", "the peri chicken",
            // "spicy chicken" all have to reach the same row.
            $table->jsonb('spoken_aliases')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            // Overrides the restaurant's default prep time for slow items.
            $table->unsignedSmallInteger('prep_minutes')->nullable();

            $table->timestamps();

            $table->unique(['restaurant_id', 'slug']);
            $table->index(['restaurant_id', 'is_available']);
            $table->index(['menu_category_id', 'sort_order']);
        });

        // Trigram index on the item name, so the fuzzy fallback in the menu
        // matcher stays fast as the menu grows.
        //
        // Aliases are scored in the same query but cannot use this index: they
        // are unnested out of a jsonb array at query time, and a GIN trigram
        // index does not reach inside one. On a menu of a few hundred items
        // that scan is sub-millisecond, and the alternative — a separate
        // aliases table purely to hold an index — costs the menu importer and
        // the Filament resource more than it saves.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX menu_items_name_trgm_index ON menu_items USING gin (name gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
