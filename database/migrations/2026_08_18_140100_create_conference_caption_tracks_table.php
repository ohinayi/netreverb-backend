<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bookkeeping only - which per-participant websocket-output egresses are
// feeding the live captions relay. No caption text is ever stored here;
// captions are ephemeral by design (see the live-captions plan).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conference_caption_tracks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conference_room_id')->constrained()->cascadeOnDelete();
            $table->string('egress_id', 128)->unique();
            $table->string('participant_identity', 191);
            $table->string('kind', 32);
            $table->string('status', 32)->default('starting')->index();
            $table->timestamps();

            $table->index(['conference_room_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conference_caption_tracks');
    }
};
