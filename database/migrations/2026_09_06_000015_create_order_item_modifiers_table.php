<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The queryable half of the modifier snapshot.
 *
 * order_items.modifiers_snapshot renders a ticket without a join; this table
 * answers "how often do callers ask for no onions?" — which is exactly the sort
 * of question that justifies having modelled removals as real rows in the first
 * place.
 *
 * Like order_items, every value here is a snapshot. `modifier_id` is a
 * nullable back-reference for the dashboard, not a source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_modifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();

            $table->foreignId('modifier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('modifier_group_id')->nullable()->constrained()->nullOnDelete();

            $table->string('group_name')->nullable();
            $table->string('name');

            // option | addon | removal | swap. Snapshotted so a ticket printed
            // a year later still knows to render "NO ONIONS" in red.
            $table->string('kind')->default('option');

            // Signed minor units, after per-item override resolution.
            $table->bigInteger('price_delta')->default(0);

            $table->unsignedSmallInteger('quantity')->default(1);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['order_item_id', 'sort_order']);
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_modifiers');
    }
};
