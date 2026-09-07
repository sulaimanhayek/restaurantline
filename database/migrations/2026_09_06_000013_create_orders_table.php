<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // Null for collection orders.
            $table->foreignId('address_id')->nullable()->constrained()->nullOnDelete();

            // Short, human-readable, and easy to read aloud down a phone line:
            // "your order number is A-4 7 2 1". Unique per restaurant.
            $table->string('order_number');

            $table->string('fulfilment_type');
            $table->string('status')->default('draft');
            $table->string('payment_status')->default('unpaid');
            $table->string('source')->default('voice');

            // All minor units. Written only by PricingService — the agent never
            // does arithmetic, and neither does anything else in this codebase.
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('delivery_fee')->default(0);
            $table->bigInteger('total')->default(0);

            // When the customer asked for it. Null means as soon as possible.
            $table->timestamp('requested_at')->nullable();

            // What we quoted them.
            $table->timestamp('estimated_ready_at')->nullable();
            $table->unsignedSmallInteger('estimated_minutes')->nullable();

            $table->text('notes')->nullable();

            // --- Call linkage -------------------------------------------------
            // The ElevenLabs conversation id, carried by every agent tool call.
            // Unique per restaurant, and the basis of order-creation
            // idempotency: a retried tool call returns the existing order
            // rather than creating a second one.
            //
            // Named explicitly rather than `conversation_id` so it is not
            // mistaken for the foreign key below. The agent's wire contract
            // still uses `conversation_id` as the field name.
            $table->string('elevenlabs_conversation_id')->nullable();

            // Set once the Conversation row exists — usually when the post-call
            // webhook arrives, which is after the order was created.
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();

            // Payment link sent by SMS after the call. Never a card number:
            // restaurantline cannot accept one, by design.
            $table->string('payment_link_url', 1024)->nullable();
            $table->string('payment_reference')->nullable();

            // Audit trail for the states that matter operationally.
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->timestamps();

            $table->unique(['restaurant_id', 'order_number']);
            $table->unique(['restaurant_id', 'elevenlabs_conversation_id']);
            $table->index(['restaurant_id', 'status']);
            $table->index(['restaurant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
