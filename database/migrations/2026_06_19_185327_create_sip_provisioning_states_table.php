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
        Schema::create('sip_provisioning_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extension_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('desired_revision')->default(1);
            $table->unsignedInteger('applied_revision')->default(0);
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sip_provisioning_states');
    }
};
