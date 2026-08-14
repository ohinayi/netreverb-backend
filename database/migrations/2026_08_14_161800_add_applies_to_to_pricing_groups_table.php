<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_groups', function (Blueprint $table): void {
            $table->string('applies_to')->default('organization')->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_groups', function (Blueprint $table): void {
            $table->dropColumn('applies_to');
        });
    }
};
