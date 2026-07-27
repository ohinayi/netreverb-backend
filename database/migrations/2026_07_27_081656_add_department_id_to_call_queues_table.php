<?php

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
        Schema::table('call_queues', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            $table->index(['organization_id', 'department_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_queues', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'department_id']);
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
