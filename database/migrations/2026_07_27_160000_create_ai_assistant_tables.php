<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assistants', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extension_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->boolean('enabled')->default(false);
            $table->string('language', 16)->default('en');
            $table->text('welcome_message')->nullable();
            $table->text('system_instruction')->nullable();
            $table->json('knowledge')->nullable();
            $table->json('handoff_rules')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'enabled']);
        });

        Schema::create('ai_assistant_fields', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('ai_assistant_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('field_type', 32)->default('text');
            $table->text('question')->nullable();
            $table->boolean('required')->default(false);
            $table->json('options')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['ai_assistant_id', 'key']);
        });

        Schema::create('ai_assistant_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_assistant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_log_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('pending');
            $table->longText('transcript')->nullable();
            $table->json('captured_data')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['ai_assistant_id', 'created_at']);
        });

        Schema::create('ai_usage_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_assistant_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 64);
            $table->string('usage_type', 64);
            $table->unsignedInteger('input_units')->default(0);
            $table->unsignedInteger('output_units')->default(0);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'usage_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_records');
        Schema::dropIfExists('ai_assistant_sessions');
        Schema::dropIfExists('ai_assistant_fields');
        Schema::dropIfExists('ai_assistants');
    }
};
