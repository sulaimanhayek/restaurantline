<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which ways a restaurant will take money.
 *
 * Two booleans rather than one `payment_method` column, because a restaurant
 * offering both is the common case and the agent's behaviour differs by how
 * many are on rather than by which: with one it never raises the subject, with
 * two it asks. See docs/DECISIONS.md #0037.
 *
 * Note what neither of them can be set to. There is no "card over the phone"
 * option, here or anywhere else in this schema, and adding one would mean
 * building a cardholder-data environment inside a call recording.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            // A Stripe Checkout link, texted to the caller after the call ends.
            $table->boolean('accepts_card_link')->default(true)->after('is_accepting_orders');

            // Cash to the driver, or at the counter.
            $table->boolean('accepts_cash')->default(true)->after('accepts_card_link');

            // How long a payment link stays live. Long enough that a caller who
            // puts their phone down and comes back to it still has one; short
            // enough that a link found in an old text is not a way to pay for
            // an order that was cancelled two months ago.
            $table->unsignedSmallInteger('payment_link_ttl_minutes')->default(60)->after('accepts_cash');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropColumn(['accepts_card_link', 'accepts_cash', 'payment_link_ttl_minutes']);
        });
    }
};
