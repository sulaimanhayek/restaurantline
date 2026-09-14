<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of what an order needs to describe how it gets paid for.
 *
 * `payment_link_url` and `payment_reference` already existed. These add the
 * method that was agreed on the call, when the link stops working, and when
 * the money actually arrived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Null until the order is confirmed: it is agreed at the end of the
            // call, not at the start.
            $table->string('payment_method')->nullable()->after('payment_status');

            $table->timestamp('payment_link_expires_at')->nullable()->after('payment_reference');
            $table->timestamp('paid_at')->nullable()->after('payment_link_expires_at');

            // The provider's idea of what was paid, in minor units. Stored
            // rather than assumed equal to `total`, because a partial capture
            // or a currency mismatch should be visible in the dashboard rather
            // than discovered during a reconciliation months later.
            $table->bigInteger('amount_paid')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_method',
                'payment_link_expires_at',
                'paid_at',
                'amount_paid',
            ]);
        });
    }
};
