<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_log_participants', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('call_log_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extension_id')->constrained();
            $table->foreignId('added_by_user_id')->constrained('users');
            $table->string('freeswitch_uuid', 64)->nullable()->index();
            $table->string('status', 20)->default('ringing');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->index(['call_log_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_log_participants');
    }
};
