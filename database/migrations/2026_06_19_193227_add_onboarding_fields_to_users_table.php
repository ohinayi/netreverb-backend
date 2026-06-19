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
        Schema::table('users', function (Blueprint $table) {
            $table->char('country_code', 2)->nullable()->after('email');
            $table->string('timezone', 64)->default('UTC')->after('country_code');
            $table->string('locale', 10)->default('en')->after('timezone');
            $table->timestamp('terms_accepted_at')->nullable()->after('locale');
            $table->timestamp('last_login_at')->nullable()->after('terms_accepted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'country_code',
                'timezone',
                'locale',
                'terms_accepted_at',
                'last_login_at',
            ]);
        });
    }
};
