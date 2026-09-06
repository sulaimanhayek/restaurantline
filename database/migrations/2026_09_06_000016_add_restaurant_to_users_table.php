<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard users belong to a restaurant.
 *
 * Nullable, because a future install may want a superuser who sees everything.
 * Present from day one for the same reason as every other restaurant_id in this
 * schema: adding it later would be a migration plus a backfill plus an audit of
 * every query that had assumed a single tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('restaurant_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('restaurant_id');
        });
    }
};
