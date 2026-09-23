<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_assistant_sessions', function (Blueprint $table): void {
            // Denormalized copy of the assistant's response_mode at the
            // moment this session started - the assistant's own setting can
            // change later, but a session already in progress should always
            // be reported/handled as whatever mode it actually ran in.
            $table->string('mode', 32)->default('turn_based')->after('status');
            $table->string('bridge_session_token', 64)->nullable()->unique()->after('freeswitch_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('ai_assistant_sessions', function (Blueprint $table): void {
            $table->dropColumn(['mode', 'bridge_session_token']);
        });
    }
};
