<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-level, not org-scoped: these are visitors to netreverb's
        // own marketing site talking to "Lisa", not a customer org's own
        // lead pipeline (that's the existing per-org Lead model).
        Schema::create('website_chat_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            // Full back-and-forth so far: [{role: 'visitor'|'lisa', text, at}, ...].
            // Re-sent as the "transcript" on every turn rather than kept as
            // live multi-turn provider state - same stateless-per-request
            // pattern AiAssistantCallFlow's own extraction already uses.
            $table->json('messages')->nullable();
            $table->string('lead_name')->nullable();
            $table->string('lead_contact')->nullable();
            $table->text('lead_need')->nullable();
            $table->timestamp('lead_notified_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_chat_sessions');
    }
};
