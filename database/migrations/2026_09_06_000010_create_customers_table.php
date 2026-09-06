<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            // E.164, always. Normalisation happens on the way in, in one place,
            // so lookups by caller ID always hit. The uniqueness is scoped to
            // the restaurant rather than global — the same person may be a
            // customer of two restaurants on a future multi-tenant install, and
            // a global unique index would make that impossible to add later.
            $table->string('phone_number');

            $table->string('name')->nullable();

            // Operator-visible free text: "always asks for extra napkins",
            // "buzzer is broken, call on arrival".
            $table->text('notes')->nullable();

            // Denormalised for the dashboard; maintained when orders confirm.
            $table->unsignedInteger('order_count')->default(0);
            $table->timestamp('first_ordered_at')->nullable();
            $table->timestamp('last_ordered_at')->nullable();

            $table->timestamps();

            $table->unique(['restaurant_id', 'phone_number']);
            $table->index('phone_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
