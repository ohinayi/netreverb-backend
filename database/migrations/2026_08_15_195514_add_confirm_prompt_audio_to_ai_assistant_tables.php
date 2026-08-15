<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Press 1 to confirm..." and "Sorry, let's try that again" are
        // fixed text shared by every assistant - cached once per voice
        // under a deterministic storage path (see
        // AiAssistantPromptSynthesizer::sharedPromptPath) rather than
        // needing their own per-assistant column.
        Schema::table('ai_assistant_fields', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_assistant_fields', 'confirm_prefix_audio_path')) {
                $table->string('confirm_prefix_audio_path')->nullable()->after('question_audio_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_assistant_fields', function (Blueprint $table): void {
            $table->dropColumn('confirm_prefix_audio_path');
        });
    }
};
