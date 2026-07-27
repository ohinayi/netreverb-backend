<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_queues', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extension_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('strategy', 32)->default('longest-idle-agent');
            $table->unsignedSmallInteger('agent_ring_timeout_seconds')->default(20);
            $table->unsignedSmallInteger('max_wait_seconds')->default(300);
            $table->string('empty_queue_action', 32)->default('end_call');
            $table->foreignId('fallback_extension_id')->nullable()->constrained('extensions')->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'enabled']);
        });

        Schema::create('call_queue_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_queue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extension_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('priority')->default(1);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['call_queue_id', 'extension_id']);
            $table->index(['call_queue_id', 'enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_queue_members');
        Schema::dropIfExists('call_queues');
    }
};
