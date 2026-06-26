<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('conference_recordings')) {
            DB::statement('ALTER TABLE conference_recordings MODIFY file_path VARCHAR(255) NOT NULL');

            $hasUniqueFilePathIndex = (bool) DB::selectOne(
                'select count(*) as aggregate from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ?',
                ['conference_recordings', 'conference_recordings_file_path_unique'],
            )->aggregate;

            if (! $hasUniqueFilePathIndex) {
                DB::statement('ALTER TABLE conference_recordings ADD UNIQUE conference_recordings_file_path_unique (file_path)');
            }

            return;
        }

        Schema::create('conference_recordings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('recording_id')->unique();
            $table->foreignId('conference_room_id')->constrained()->cascadeOnDelete();
            $table->string('room_id', 64)->index();
            $table->string('call_id', 64)->index();
            $table->string('file_path', 255)->unique();
            $table->string('file_name', 255);
            $table->unsignedInteger('duration')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('status', 32)->default('starting')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['conference_room_id', 'status']);
            $table->index(['room_id', 'status']);
            $table->index(['deleted_at', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conference_recordings');
    }
};
