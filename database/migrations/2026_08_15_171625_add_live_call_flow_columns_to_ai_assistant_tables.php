<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_assistant_fields', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_assistant_fields', 'question_audio_path')) {
                $table->string('question_audio_path')->nullable()->after('question');
            }
        });

        Schema::table('ai_assistant_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_assistant_sessions', 'freeswitch_uuid')) {
                $table->string('freeswitch_uuid')->nullable()->after('call_log_id');
            }
            if (! Schema::hasColumn('ai_assistant_sessions', 'current_field_key')) {
                $table->string('current_field_key')->nullable()->after('captured_data');
            }
            if (! Schema::hasColumn('ai_assistant_sessions', 'pending_value')) {
                $table->text('pending_value')->nullable()->after('current_field_key');
            }
            if (! Schema::hasColumn('ai_assistant_sessions', 'retry_count')) {
                $table->unsignedTinyInteger('retry_count')->default(0)->after('pending_value');
            }
            if (! Schema::hasColumn('ai_assistant_sessions', 'freeswitch_uuid')) {
                return;
            }
            $indexExists = collect(Schema::getIndexes('ai_assistant_sessions'))->contains('name', 'ai_assistant_sessions_freeswitch_uuid_index');
            if (! $indexExists) {
                Schema::table('ai_assistant_sessions', function (Blueprint $table): void {
                    $table->index('freeswitch_uuid');
                });
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_assistant_sessions', function (Blueprint $table): void {
            $table->dropIndex('ai_assistant_sessions_freeswitch_uuid_index');
            $table->dropColumn(['freeswitch_uuid', 'current_field_key', 'pending_value', 'retry_count']);
        });

        Schema::table('ai_assistant_fields', function (Blueprint $table): void {
            $table->dropColumn('question_audio_path');
        });
    }
};
