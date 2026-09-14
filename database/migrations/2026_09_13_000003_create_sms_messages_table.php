<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every text this application sends, and what happened to it.
 *
 * The question this table exists to answer is "the customer says they never got
 * the link" — which is asked on the phone, under time pressure, by somebody who
 * needs to know within about fifteen seconds whether to resend it or to start
 * looking at the phone number. A log file cannot be queried from the dashboard
 * and a provider's own console is a different login and a different search box.
 *
 * It is also the audit trail for the one outbound channel that costs money per
 * use, which makes a runaway loop something you can see rather than something
 * you find on an invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            // Both nullable: a message may be about an order, about a customer,
            // about both, or about neither — a test send from the dashboard has
            // no subject at all.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // E.164, as given to the provider. Stored rather than read back
            // through the customer relation, because the number a text was sent
            // to is a fact about the text and a customer can change theirs.
            $table->string('to_number', 32);

            $table->string('kind')->default('order_confirmation');
            $table->string('status')->default('queued');

            // The message as sent. It contains an order number, a total and
            // possibly a payment URL — never a card number, and nothing here
            // can put one in it.
            $table->text('body');

            $table->string('provider')->nullable();
            $table->string('provider_message_id')->nullable();

            // Why it failed, in the provider's words, for the person trying to
            // work out whether to resend.
            $table->text('error')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'created_at']);
            $table->index(['restaurant_id', 'status']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
