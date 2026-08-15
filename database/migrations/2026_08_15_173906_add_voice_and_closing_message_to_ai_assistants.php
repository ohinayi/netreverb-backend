<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_assistants', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_assistants', 'tts_voice')) {
                $table->string('tts_voice', 64)->nullable()->after('language');
            }
            if (! Schema::hasColumn('ai_assistants', 'welcome_audio_path')) {
                $table->string('welcome_audio_path')->nullable()->after('welcome_message');
            }
            if (! Schema::hasColumn('ai_assistants', 'closing_message')) {
                $table->text('closing_message')->nullable()->after('welcome_audio_path');
            }
            if (! Schema::hasColumn('ai_assistants', 'closing_audio_path')) {
                $table->string('closing_audio_path')->nullable()->after('closing_message');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_assistants', function (Blueprint $table): void {
            $table->dropColumn(['tts_voice', 'welcome_audio_path', 'closing_message', 'closing_audio_path']);
        });
    }
};
