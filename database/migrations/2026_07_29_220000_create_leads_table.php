<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('status', 32)->default('new');
            $table->decimal('value', 14, 2)->nullable();
            $table->longText('notes')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('follow_up_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['organization_id', 'assigned_user_id']);
            $table->index(['organization_id', 'follow_up_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
