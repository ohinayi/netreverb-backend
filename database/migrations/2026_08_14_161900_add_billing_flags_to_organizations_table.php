<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            // Nothing is paywalled by default - every existing and new
            // organization keeps today's full free access until an admin
            // explicitly activates billing for it. Once active, features are
            // only available once payment_confirmed is also true.
            $table->boolean('payment_required')->default(false)->after('pricing_group_id');
            $table->boolean('payment_confirmed')->default(false)->after('payment_required');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['payment_required', 'payment_confirmed']);
        });
    }
};
