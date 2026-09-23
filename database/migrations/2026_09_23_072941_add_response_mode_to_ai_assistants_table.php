<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_assistants', function (Blueprint $table): void {
            $table->string('response_mode', 32)->default('turn_based')->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('ai_assistants', function (Blueprint $table): void {
            $table->dropColumn('response_mode');
        });
    }
};
