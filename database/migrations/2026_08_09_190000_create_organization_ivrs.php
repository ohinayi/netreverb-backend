<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organization_ivrs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            // Organizations use an integer primary key and expose public_id for URLs.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('welcome_text')->nullable();
            $table->string('welcome_audio_path')->nullable();
            $table->unsignedTinyInteger('max_retries')->default(2);
            $table->unsignedSmallInteger('timeout_seconds')->default(5);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'enabled']);
        });

        Schema::create('organization_ivr_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_ivr_id')->constrained()->cascadeOnDelete();
            $table->string('digit', 1);
            $table->string('label');
            $table->string('destination_type');
            $table->string('destination')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['organization_ivr_id', 'digit']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_ivr_options');
        Schema::dropIfExists('organization_ivrs');
    }
};
