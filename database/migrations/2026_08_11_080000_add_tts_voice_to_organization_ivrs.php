<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('organization_ivrs', function (Blueprint $table): void {
            $table->string('tts_voice', 64)->default('en_US-lessac-medium')->after('welcome_audio_path');
        });
    }

    public function down(): void
    {
        Schema::table('organization_ivrs', function (Blueprint $table): void {
            $table->dropColumn('tts_voice');
        });
    }
};
