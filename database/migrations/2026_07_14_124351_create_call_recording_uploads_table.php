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
        Schema::create('call_recording_uploads', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_log_id')->constrained()->cascadeOnDelete();
            $table->char('recording_id', 26)->unique();
            $table->string('status', 32);
            $table->enum('media_type', ['video']);
            $table->string('container', 16);
            $table->string('mime_type', 191)->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedInteger('next_sequence')->default(0);
            $table->unsignedInteger('uploaded_chunks_count')->default(0);
            $table->unsignedBigInteger('uploaded_size')->default(0);
            $table->timestamp('upload_started_at')->nullable();
            $table->timestamp('last_chunk_received_at')->nullable();
            $table->timestamp('upload_completed_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique('call_log_id');
            $table->index(['organization_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_recording_uploads');
    }
};
