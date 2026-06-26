<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('caller_extension_id')->nullable()->constrained('extensions')->nullOnDelete();
            $table->foreignId('callee_extension_id')->nullable()->constrained('extensions')->nullOnDelete();
            $table->string('caller_number');
            $table->string('callee_number');
            $table->string('status', 32)->default('ringing');
            $table->unsignedInteger('duration')->default(0); // in seconds
            $table->string('recording_url')->nullable();
            $table->unsignedInteger('recording_duration')->nullable(); // in seconds
            $table->unsignedBigInteger('recording_size')->nullable(); // in bytes
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'created_at']);
            $table->index('caller_extension_id');
            $table->index('callee_extension_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};
