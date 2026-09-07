<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            // --- Availability window ------------------------------------------
            // Null on both times means "available whenever the restaurant is
            // open". Set both for a lunch-only category. Times are local
            // wall-clock in the restaurant's timezone.
            $table->time('available_from')->nullable();
            $table->time('available_until')->nullable();

            // List of weekday numbers (0 = Sunday) this category is served on.
            // Null means every day. A Sunday roast category is [0].
            $table->jsonb('available_days')->nullable();

            $table->timestamps();

            $table->unique(['restaurant_id', 'slug']);
            $table->index(['restaurant_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_categories');
    }
};
