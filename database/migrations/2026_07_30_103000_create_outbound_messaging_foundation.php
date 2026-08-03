<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('message_templates')) {
            Schema::create('message_templates', function (Blueprint $table) {
                $table->id();
                $table->ulid('public_id')->unique();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by_user_id')->constrained('users');
                $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name');
                $table->string('channel', 20);
                $table->text('body');
                $table->string('status', 30)->default('draft');
                $table->text('review_note')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['organization_id', 'channel', 'status']);
            });
        }

        if (! Schema::hasTable('lead_contact_channels')) {
            Schema::create('lead_contact_channels', function (Blueprint $table) {
                $table->id();
                $table->ulid('public_id')->unique();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
                $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('channel', 20);
                $table->string('destination');
                $table->string('consent_status', 30)->default('unknown');
                $table->string('consent_source')->nullable();
                $table->timestamp('consented_at')->nullable();
                $table->timestamp('suppressed_at')->nullable();
                $table->string('suppression_reason')->nullable();
                $table->timestamps();
                $table->unique(['lead_id', 'channel']);
                $table->index(['organization_id', 'channel', 'consent_status'], 'lead_contact_consent_idx');
            });
        }

        if (! Schema::hasTable('outbound_messages')) {
            Schema::create('outbound_messages', function (Blueprint $table) {
                $table->id();
                $table->ulid('public_id')->unique();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
                $table->foreignId('message_template_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('created_by_user_id')->constrained('users');
                $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('channel', 20);
                $table->string('destination');
                $table->text('body');
                $table->string('status', 30)->default('draft');
                $table->string('blocked_reason')->nullable();
                $table->json('consent_snapshot')->nullable();
                $table->string('provider')->nullable();
                $table->string('provider_message_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->text('failure_reason')->nullable();
                $table->timestamps();
                $table->index(['organization_id', 'status', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_messages');
        Schema::dropIfExists('lead_contact_channels');
        Schema::dropIfExists('message_templates');
    }
};
