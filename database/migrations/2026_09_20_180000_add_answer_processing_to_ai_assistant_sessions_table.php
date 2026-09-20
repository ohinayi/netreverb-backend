<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_assistant_sessions', function (Blueprint $table): void {
            // Transcription + extraction moved to a queued background job
            // (ProcessLiveAiAssistantAnswer) so the caller hears a wait
            // sound instead of dead air while it runs. Set when the job for
            // the CURRENT answer is dispatched; answer_ready_at is set by
            // the job once pending_value is actually populated. Both null
            // again once the flow moves past the field they were for.
            $table->timestamp('answer_processing_started_at')->nullable()->after('pending_value');
            $table->timestamp('answer_ready_at')->nullable()->after('answer_processing_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_assistant_sessions', function (Blueprint $table): void {
            $table->dropColumn(['answer_processing_started_at', 'answer_ready_at']);
        });
    }
};
