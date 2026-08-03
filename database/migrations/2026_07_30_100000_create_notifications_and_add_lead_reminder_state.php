<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('follow_up_notified_at')->nullable()->after('follow_up_at');
            $table->timestamp('follow_up_completed_at')->nullable()->after('follow_up_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['follow_up_notified_at', 'follow_up_completed_at']);
        });

        Schema::dropIfExists('notifications');
    }
};
