<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conference_rooms', function (Blueprint $table) {
            $table->timestamp('starts_at')->nullable()->index()->after('title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conference_rooms', function (Blueprint $table) {
            $table->dropColumn('starts_at');
        });
    }
};
