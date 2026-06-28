<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table): void {
            $table->string('freeswitch_uuid', 64)->nullable()->index()->after('duration');
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table): void {
            $table->dropIndex(['freeswitch_uuid']);
            $table->dropColumn('freeswitch_uuid');
        });
    }
};
