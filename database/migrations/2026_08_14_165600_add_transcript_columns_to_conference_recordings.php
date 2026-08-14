<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The abdulhafeez branch's conference-transcription feature (models,
// controller, job) shipped without this migration - the columns it reads/
// writes never existed, which broke even unrelated existing behavior like
// deleting a conference recording. Inferred from the model casts/fillable
// and the feature's own tests. Guarded with hasColumn/hasTable throughout:
// a first attempt at this migration partially applied on prod before
// failing on an over-length FK constraint name, so a plain re-run would
// otherwise fail on "column/table already exists".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conference_recordings', function (Blueprint $table): void {
            if (! Schema::hasColumn('conference_recordings', 'transcription_enabled')) {
                $table->boolean('transcription_enabled')->default(false)->after('download_url');
            }
            if (! Schema::hasColumn('conference_recordings', 'transcript_status')) {
                $table->string('transcript_status')->default('pending')->after('transcription_enabled');
            }
            if (! Schema::hasColumn('conference_recordings', 'transcript_file_path')) {
                $table->string('transcript_file_path')->nullable()->after('transcript_status');
            }
            if (! Schema::hasColumn('conference_recordings', 'transcript_file_name')) {
                $table->string('transcript_file_name')->nullable()->after('transcript_file_path');
            }
            if (! Schema::hasColumn('conference_recordings', 'transcript_size')) {
                $table->unsignedInteger('transcript_size')->nullable()->after('transcript_file_name');
            }
            if (! Schema::hasColumn('conference_recordings', 'transcript_error')) {
                $table->text('transcript_error')->nullable()->after('transcript_size');
            }
            if (! Schema::hasColumn('conference_recordings', 'transcript_completed_at')) {
                $table->timestamp('transcript_completed_at')->nullable()->after('transcript_error');
            }
        });

        Schema::table('conference_recording_tracks', function (Blueprint $table): void {
            if (! Schema::hasColumn('conference_recording_tracks', 'transcript_status')) {
                $table->string('transcript_status')->default('pending')->after('status');
            }
            if (! Schema::hasColumn('conference_recording_tracks', 'transcript_error')) {
                $table->text('transcript_error')->nullable()->after('transcript_status');
            }
            if (! Schema::hasColumn('conference_recording_tracks', 'transcript_started_at')) {
                $table->timestamp('transcript_started_at')->nullable()->after('transcript_error');
            }
            if (! Schema::hasColumn('conference_recording_tracks', 'transcript_completed_at')) {
                $table->timestamp('transcript_completed_at')->nullable()->after('transcript_started_at');
            }
        });

        if (! Schema::hasTable('conference_recording_transcript_segments')) {
            Schema::create('conference_recording_transcript_segments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('conference_recording_track_id');
                $table->unsignedInteger('segment_index');
                $table->unsignedInteger('start_ms');
                $table->unsignedInteger('end_ms');
                $table->text('text');
                $table->timestamps();

                $table->index(['conference_recording_track_id', 'segment_index']);
            });
        }

        // MySQL's auto-generated FK constraint name for this column/table
        // pair exceeds its own 64-char identifier limit, so it has to be
        // named explicitly - and information_schema (used to check whether
        // that constraint already exists, since a first attempt at this
        // migration partially applied on prod before failing on exactly
        // this) is a MySQL-specific concept the sqlite test database
        // doesn't have, so only bother checking there.
        $hasConstraint = DB::getDriverName() === 'mysql' && (bool) DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', 'conference_recording_transcript_segments')
            ->where('COLUMN_NAME', 'conference_recording_track_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();

        if (! $hasConstraint) {
            Schema::table('conference_recording_transcript_segments', function (Blueprint $table): void {
                $table->foreign('conference_recording_track_id', 'crts_track_id_fk')
                    ->references('id')->on('conference_recording_tracks')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conference_recording_transcript_segments');

        Schema::table('conference_recording_tracks', function (Blueprint $table): void {
            $table->dropColumn(['transcript_status', 'transcript_error', 'transcript_started_at', 'transcript_completed_at']);
        });

        Schema::table('conference_recordings', function (Blueprint $table): void {
            $table->dropColumn([
                'transcription_enabled', 'transcript_status', 'transcript_file_path',
                'transcript_file_name', 'transcript_size', 'transcript_error', 'transcript_completed_at',
            ]);
        });
    }
};
