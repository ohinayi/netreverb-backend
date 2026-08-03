<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('sms_credit_purchase_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->string('provider', 30);
            $table->string('provider_event_id', 160);
            $table->string('event_type', 100)->nullable();
            $table->string('status', 30)->default('received');
            $table->string('payload_hash', 64);
            $table->json('metadata')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_event_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
