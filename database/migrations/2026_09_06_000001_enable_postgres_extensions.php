<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enables the PostgreSQL extensions the menu matcher relies on.
 *
 * `pg_trgm` provides trigram similarity, which is how a caller saying
 * "the peri peri chicken thing" gets matched to "Peri Peri Chicken Wrap" when
 * none of the exact aliases hit. It ships with PostgreSQL as a contrib module,
 * so no extra install is needed — but it does have to be enabled per database.
 *
 * `unaccent` lets "creme brulee" match "Crème brûlée", which callers will never
 * pronounce with the accents intact and speech-to-text will never transcribe
 * with them either.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Deliberately not dropped: other things in the database may depend on
        // them, and dropping an extension is not the kind of surprise a
        // rollback should contain.
    }
};
