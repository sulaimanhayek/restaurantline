<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order lines, stored as snapshots.
 *
 * Every name and price is copied at the time of ordering and never read back
 * from the live menu row. A price rise next Tuesday must not silently rewrite
 * what a customer was quoted last Friday, and a deleted menu item must not
 * turn a historical order into a blank line on the kitchen ticket.
 *
 * `menu_item_id` is kept alongside the snapshot, nullable, purely so the
 * dashboard can link back to the current item where it still exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Reference only. Never the source of name or price.
            $table->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('sku')->nullable();

            // Unit price at time of order, minor units, before modifiers.
            $table->bigInteger('unit_price');

            $table->unsignedSmallInteger('quantity')->default(1);

            // (unit_price + sum of modifier deltas) * quantity. Minor units.
            $table->bigInteger('line_total');

            // Human-readable snapshot of the chosen modifiers, denormalised so
            // a kitchen ticket or a receipt can be rendered from this row alone
            // without a join. The queryable form lives in order_item_modifiers.
            $table->jsonb('modifiers_snapshot')->nullable();

            // Anything the caller said about this specific line that did not
            // map to a modifier. Read by the kitchen, not by the pricing code.
            $table->text('notes')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['order_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
