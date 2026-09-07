<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable modifier groups.
 *
 * Groups are authored once per restaurant and attached to many items. The
 * attaching pivot may override min/max/required per item, so a single "Size"
 * group can be required on a main and optional on a side without duplicating
 * it. See docs/DECISIONS.md #0002.
 *
 * Duplication is worth avoiding here specifically because each copy would
 * accumulate its own drifting set of spoken aliases, and the agent's matching
 * quality would quietly degrade as they diverged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');

            // What the agent says when prompting for this group:
            // "What size would you like?"
            $table->string('prompt')->nullable();

            // single | multi — see App\Enums\ModifierGroupSelectionType.
            $table->string('selection_type')->default('single');

            $table->unsignedSmallInteger('min_selections')->default(0);

            // Null means unlimited.
            $table->unsignedSmallInteger('max_selections')->nullable();

            $table->boolean('is_required')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['restaurant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_groups');
    }
};
