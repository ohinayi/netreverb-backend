<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_assistant_fields', function (Blueprint $table): void {
            // Every speech-answered field currently reads its captured value
            // back and waits for a confirm/redo digit press, every time -
            // safe, but slow and repetitive across a multi-field call. This
            // lets an org skip that round-trip for fields where they trust
            // transcription accuracy, without touching the fields (phone,
            // boolean, number) that already skip it by going straight to
            // DTMF.
            $table->boolean('skip_confirmation')->default(false)->after('required');
        });
    }

    public function down(): void
    {
        Schema::table('ai_assistant_fields', function (Blueprint $table): void {
            $table->dropColumn('skip_confirmation');
        });
    }
};
