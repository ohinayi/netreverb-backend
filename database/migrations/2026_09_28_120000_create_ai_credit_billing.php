<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_pricing_settings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('provider', 40)->unique();
            $table->string('currency', 3)->default('NGN');
            $table->unsignedInteger('cost_per_unit_minor')->default(3100);
            $table->unsignedInteger('selling_per_unit_minor')->default(5000);
            $table->unsignedInteger('minimum_purchase_minor')->default(500000);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_credit_wallets', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            // Signed, unlike sms_wallets.balance_units - a call's real
            // duration (and therefore its cost) isn't known until it ends,
            // so debiting happens AFTER the call rather than before like
            // SMS does. The last call before a top-up can dip the balance
            // slightly negative (same as prepaid mobile airtime); the
            // pre-flight check in AiAssistantRealtimeCallFlow blocks the
            // NEXT call once balance_units < 1, it just can't prevent one
            // already-running call from going over.
            $table->bigInteger('balance_units')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_credit_purchases', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('payment_reference')->nullable()->unique();
            $table->string('payment_method', 40)->default('admin');
            $table->string('currency', 3)->default('NGN');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('units');
            $table->unsignedInteger('cost_per_unit_minor');
            $table->unsignedInteger('selling_per_unit_minor');
            $table->unsignedBigInteger('profit_minor');
            $table->string('status', 30)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'created_at'], 'ai_purchase_org_status_idx');
        });

        Schema::create('ai_credit_wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('ai_credit_wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_credit_purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_assistant_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key')->unique();
            $table->string('type', 30);
            $table->bigInteger('units');
            $table->bigInteger('balance_after');
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ai_credit_wallet_id', 'created_at'], 'ai_credit_wallet_txn_wallet_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_wallet_transactions');
        Schema::dropIfExists('ai_credit_purchases');
        Schema::dropIfExists('ai_credit_wallets');
        Schema::dropIfExists('ai_pricing_settings');
    }
};
