<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conference_recording_tracks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conference_recording_id')->constrained()->cascadeOnDelete();
            $table->string('egress_id', 128)->unique();
            $table->string('participant_identity', 191);
            $table->string('kind', 32)->default('participant');
            $table->string('storage_key', 512);
            $table->string('status', 32)->default('starting')->index();
            $table->unsignedInteger('duration')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();

            $table->index(['conference_recording_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conference_recording_tracks');
    }
};
