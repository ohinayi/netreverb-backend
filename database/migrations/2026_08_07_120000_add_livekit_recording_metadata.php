<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conference_recordings', function (Blueprint $table): void {
            $table->string('egress_id', 128)->nullable()->unique()->after('call_id');
            $table->string('storage_key', 512)->nullable()->after('file_name');
            $table->text('download_url')->nullable()->after('storage_key');
        });
    }

    public function down(): void
    {
        Schema::table('conference_recordings', function (Blueprint $table): void {
            $table->dropUnique(['egress_id']);
            $table->dropColumn(['egress_id', 'storage_key', 'download_url']);
        });
    }
};
