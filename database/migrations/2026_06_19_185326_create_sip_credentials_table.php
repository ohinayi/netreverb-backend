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
        Schema::create('sip_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extension_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('password');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sip_credentials');
    }
};
