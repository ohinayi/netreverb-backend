<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_activities', function (Blueprint $table): void {
            $table->foreignId('call_log_id')->nullable()->after('actor_user_id')
                ->constrained('call_logs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lead_activities', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('call_log_id');
        });
    }
};
