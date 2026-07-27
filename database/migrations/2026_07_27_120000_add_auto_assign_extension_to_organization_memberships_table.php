<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_memberships', function (Blueprint $table): void {
            $table->boolean('auto_assign_extension')->default(false)->after('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('organization_memberships', function (Blueprint $table): void {
            $table->dropColumn('auto_assign_extension');
        });
    }
};
