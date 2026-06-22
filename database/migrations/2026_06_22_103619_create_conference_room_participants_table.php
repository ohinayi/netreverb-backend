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
        Schema::create('conference_room_participants', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('conference_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('display_name');
            $table->string('email')->nullable()->index();
            $table->string('role', 32)->default('participant')->index();
            $table->string('status', 32)->default('invited')->index();
            $table->timestamp('invited_at')->nullable()->index();
            $table->timestamp('joined_at')->nullable()->index();
            $table->timestamp('left_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['conference_room_id', 'user_id']);
            $table->unique(['conference_room_id', 'email']);
            $table->index(['conference_room_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conference_room_participants');
    }
};
