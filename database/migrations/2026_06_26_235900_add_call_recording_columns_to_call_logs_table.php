<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table): void {
            $table->ulid('recording_id')->nullable()->unique()->after('duration');
            $table->string('recording_uuid', 64)->nullable()->index()->after('recording_url');
            $table->string('recording_file_path', 255)->nullable()->unique()->after('recording_uuid');
            $table->string('recording_file_name', 255)->nullable()->after('recording_file_path');
            $table->string('recording_status', 32)->nullable()->index()->after('recording_file_name');
            $table->timestamp('recording_started_at')->nullable()->after('recording_status');
            $table->timestamp('recording_ended_at')->nullable()->after('recording_started_at');

            $table->index(['organization_id', 'recording_status']);
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'recording_status']);
            $table->dropColumn([
                'recording_id',
                'recording_uuid',
                'recording_file_path',
                'recording_file_name',
                'recording_status',
                'recording_started_at',
                'recording_ended_at',
            ]);
        });
    }
};
