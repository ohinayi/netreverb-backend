<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Retires the Whisper-batch/docx transcript pipeline in favor of live
// Deepgram captions (ephemeral, not persisted). Reverses
// 2026_08_14_165600_add_transcript_columns_to_conference_recordings.php.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('conference_recording_transcript_segments');

        if (Schema::hasColumn('conference_recording_tracks', 'transcript_status')) {
            Schema::table('conference_recording_tracks', function (Blueprint $table): void {
                $table->dropColumn(['transcript_status', 'transcript_error', 'transcript_started_at', 'transcript_completed_at']);
            });
        }

        if (Schema::hasColumn('conference_recordings', 'transcription_enabled')) {
            Schema::table('conference_recordings', function (Blueprint $table): void {
                $table->dropColumn([
                    'transcription_enabled', 'transcript_status', 'transcript_file_path',
                    'transcript_file_name', 'transcript_size', 'transcript_error', 'transcript_completed_at',
                ]);
            });
        }
    }

    public function down(): void
    {
        Schema::table('conference_recordings', function (Blueprint $table): void {
            $table->boolean('transcription_enabled')->default(false)->after('download_url');
            $table->string('transcript_status')->default('pending')->after('transcription_enabled');
            $table->string('transcript_file_path')->nullable()->after('transcript_status');
            $table->string('transcript_file_name')->nullable()->after('transcript_file_path');
            $table->unsignedInteger('transcript_size')->nullable()->after('transcript_file_name');
            $table->text('transcript_error')->nullable()->after('transcript_size');
            $table->timestamp('transcript_completed_at')->nullable()->after('transcript_error');
        });

        Schema::table('conference_recording_tracks', function (Blueprint $table): void {
            $table->string('transcript_status')->default('pending')->after('status');
            $table->text('transcript_error')->nullable()->after('transcript_status');
            $table->timestamp('transcript_started_at')->nullable()->after('transcript_error');
            $table->timestamp('transcript_completed_at')->nullable()->after('transcript_started_at');
        });

        Schema::create('conference_recording_transcript_segments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conference_recording_track_id');
            $table->unsignedInteger('segment_index');
            $table->unsignedInteger('start_ms');
            $table->unsignedInteger('end_ms');
            $table->text('text');
            $table->timestamps();

            $table->index(['conference_recording_track_id', 'segment_index']);
            $table->foreign('conference_recording_track_id', 'crts_track_id_fk')
                ->references('id')->on('conference_recording_tracks')
                ->cascadeOnDelete();
        });
    }
};
