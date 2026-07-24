<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extensions', function (Blueprint $table): void {
            $table->string('unavailable_action', 32)->default('return_to_sender')->after('status');
            $table->foreignId('fallback_extension_id')
                ->nullable()
                ->after('unavailable_action')
                ->constrained('extensions')
                ->nullOnDelete();
            $table->unsignedSmallInteger('ring_timeout_seconds')->default(20)->after('fallback_extension_id');
        });
    }

    public function down(): void
    {
        Schema::table('extensions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fallback_extension_id');
            $table->dropColumn(['unavailable_action', 'ring_timeout_seconds']);
        });
    }
};
