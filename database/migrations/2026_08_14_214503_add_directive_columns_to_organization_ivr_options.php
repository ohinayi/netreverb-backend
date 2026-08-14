<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_ivr_options', function (Blueprint $table): void {
            if (! Schema::hasColumn('organization_ivr_options', 'directive_text')) {
                $table->text('directive_text')->nullable()->after('destination');
            }
            if (! Schema::hasColumn('organization_ivr_options', 'directive_audio_path')) {
                $table->string('directive_audio_path')->nullable()->after('directive_text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organization_ivr_options', function (Blueprint $table): void {
            $table->dropColumn(['directive_text', 'directive_audio_path']);
        });
    }
};
