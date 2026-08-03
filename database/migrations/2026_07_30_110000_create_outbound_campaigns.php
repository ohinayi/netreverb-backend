<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->unique()->after('public_id');
        });

        Schema::create('outbound_campaigns', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_template_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->string('name');
            $table->string('channel', 20);
            $table->string('status', 30)->default('draft');
            $table->string('timezone')->default('Africa/Lagos');
            $table->time('quiet_hours_start')->default('20:00');
            $table->time('quiet_hours_end')->default('08:00');
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(30);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'status', 'scheduled_at'], 'campaign_schedule_idx');
        });

        Schema::create('outbound_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('outbound_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outbound_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('pending');
            $table->string('blocked_reason')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['outbound_campaign_id', 'lead_id'], 'campaign_lead_unique');
            $table->index(['outbound_campaign_id', 'status'], 'campaign_recipient_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_campaign_recipients');
        Schema::dropIfExists('outbound_campaigns');
        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
