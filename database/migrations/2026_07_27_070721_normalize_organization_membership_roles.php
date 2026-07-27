<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organization_memberships', function (Blueprint $table): void {
            $table->string('legacy_role', 32)->nullable()->after('role');
        });

        DB::table('organization_memberships')
            ->whereIn('role', ['member', 'telephony_admin', 'department_manager', 'auditor'])
            ->update(['legacy_role' => DB::raw('role')]);

        DB::table('organization_memberships')->where('role', 'member')->update(['role' => 'agent']);
        DB::table('organization_memberships')->where('role', 'telephony_admin')->update(['role' => 'admin']);
        DB::table('organization_memberships')->where('role', 'department_manager')->update(['role' => 'supervisor']);
        DB::table('organization_memberships')->where('role', 'auditor')->update(['role' => 'agent']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('organization_memberships')
            ->whereNotNull('legacy_role')
            ->update(['role' => DB::raw('legacy_role')]);

        Schema::table('organization_memberships', function (Blueprint $table): void {
            $table->dropColumn('legacy_role');
        });
    }
};
