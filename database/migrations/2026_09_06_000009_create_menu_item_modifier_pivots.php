<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two pivots that attach modifiers to items.
 *
 * `menu_item_modifier_group` is the real relationship: which groups an item
 * offers, in what order, and — via the three nullable override columns —
 * whether this particular item tightens or relaxes the group's own rules.
 * Resolution is always `override ?? group value`, done in one place so it
 * cannot drift.
 *
 * `menu_item_modifier` is sparse and exists only for price exceptions: rows
 * appear here solely where an item prices a modifier differently from the
 * modifier's own default. Most menus will have a handful of rows or none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_modifier_group', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);

            // Null means "inherit from the group".
            $table->unsignedSmallInteger('min_selections_override')->nullable();
            $table->unsignedSmallInteger('max_selections_override')->nullable();
            $table->boolean('is_required_override')->nullable();

            $table->timestamps();

            $table->unique(['menu_item_id', 'modifier_group_id']);
        });

        Schema::create('menu_item_modifier', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_id')->constrained()->cascadeOnDelete();

            // Signed minor units, replacing modifiers.price_delta for this item.
            $table->bigInteger('price_delta_override')->nullable();

            // Lets an item hide one option from a shared group — a vegan burger
            // offering the standard "Extras" group but not "Add bacon".
            $table->boolean('is_available_override')->nullable();

            $table->timestamps();

            $table->unique(['menu_item_id', 'modifier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_modifier');
        Schema::dropIfExists('menu_item_modifier_group');
    }
};
