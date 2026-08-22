<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ringback_ads', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            // Null organization_id means the super-admin authored this clip
            // directly rather than an organization submitting it for review.
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('audio_path');
            $table->string('status')->default('pending');
            $table->boolean('enabled')->default(true);
            $table->string('rejection_reason')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'enabled']);
        });

        Schema::table('organizations', function (Blueprint $table): void {
            // A standalone toggle purchase, deliberately separate from
            // payment_required/payment_confirmed/pricing_group - those gate
            // feature access broadly, this gates just whether the org's own
            // ringback audio plays instead of the shared ad pool.
            $table->boolean('ad_exempt')->default(false)->after('payment_confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('ad_exempt');
        });
        Schema::dropIfExists('ringback_ads');
    }
};
