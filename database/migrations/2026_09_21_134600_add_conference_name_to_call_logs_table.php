<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table): void {
            $table->string('conference_name', 128)->nullable()->index()->after('freeswitch_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table): void {
            $table->dropIndex(['conference_name']);
            $table->dropColumn('conference_name');
        });
    }
};
