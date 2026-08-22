<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            // Tracks an org's self-service exemption request separately from
            // ad_exempt itself, so a super admin can distinguish "never
            // asked" from "asked and we said no" from "asked and granted".
            $table->string('ad_exemption_status')->nullable()->after('ad_exempt');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('ad_exemption_status');
        });
    }
};
