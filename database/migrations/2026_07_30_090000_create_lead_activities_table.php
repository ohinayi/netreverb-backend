<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_activities', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 48);
            $table->text('summary');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'created_at']);
            $table->index(['organization_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
    }
};
