<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('organization_ivrs', function (Blueprint $table): void {
            $table->decimal('tts_speed', 3, 2)->default(1.00)->after('tts_voice');
        });
    }

    public function down(): void
    {
        Schema::table('organization_ivrs', function (Blueprint $table): void {
            $table->dropColumn('tts_speed');
        });
    }
};
