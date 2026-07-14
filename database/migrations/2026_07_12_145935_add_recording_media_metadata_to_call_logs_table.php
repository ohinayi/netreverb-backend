<?php

use App\Enums\CallRecordingMediaType;
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
        Schema::table('call_logs', function (Blueprint $table): void {
            $table->enum('recording_media_type', array_map(
                static fn (CallRecordingMediaType $mediaType): string => $mediaType->value,
                CallRecordingMediaType::cases(),
            ))->nullable()->after('recording_uuid');
            $table->string('recording_container', 16)->nullable()->after('recording_media_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table): void {
            $table->dropColumn(['recording_media_type', 'recording_container']);
        });
    }
};
