<?php

use App\Enums\CallMediaType;
use App\Enums\CallSessionType;
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
            $table->enum('media_type', array_map(
                static fn (CallMediaType $mediaType): string => $mediaType->value,
                CallMediaType::cases(),
            ))->default(CallMediaType::Audio->value)->after('status');
            $table->enum('session_type', array_map(
                static fn (CallSessionType $sessionType): string => $sessionType->value,
                CallSessionType::cases(),
            ))->default(CallSessionType::Direct->value)->after('media_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table): void {
            $table->dropColumn(['media_type', 'session_type']);
        });
    }
};
