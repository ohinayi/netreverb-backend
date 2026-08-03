<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_pricing_settings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('provider', 40)->unique();
            $table->string('currency', 3)->default('NGN');
            $table->unsignedInteger('cost_per_unit_minor')->default(200);
            $table->unsignedInteger('selling_per_unit_minor')->default(500);
            $table->unsignedInteger('minimum_purchase_minor')->default(500000);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sms_wallets', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('balance_units')->default(0);
            $table->timestamps();
        });

        Schema::create('sms_credit_purchases', function (Blueprint $table): void {
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
            $table->index(['organization_id', 'status', 'created_at'], 'sms_purchase_org_status_idx');
        });

        Schema::create('sms_wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('sms_wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sms_credit_purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('outbound_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key')->unique();
            $table->string('type', 30);
            $table->bigInteger('units');
            $table->unsignedBigInteger('balance_after');
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['sms_wallet_id', 'created_at']);
        });

        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->unsignedSmallInteger('sms_units')->default(0)->after('body');
            $table->string('billing_status', 30)->nullable()->after('sms_units');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->dropColumn(['sms_units', 'billing_status']);
        });
        Schema::dropIfExists('sms_wallet_transactions');
        Schema::dropIfExists('sms_credit_purchases');
        Schema::dropIfExists('sms_wallets');
        Schema::dropIfExists('sms_pricing_settings');
    }
};
