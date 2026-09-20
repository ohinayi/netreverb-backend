<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voicemails', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('extension_id')->constrained()->cascadeOnDelete();
            $table->string('caller_number');
            $table->string('file_path');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->timestamp('listened_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['extension_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voicemails');
    }
};
