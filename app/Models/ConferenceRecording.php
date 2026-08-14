<?php

namespace App\Models;

use App\Enums\ConferenceRecordingStatus;
use App\Enums\ConferenceTranscriptStatus;
use Database\Factories\ConferenceRecordingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'conference_room_id',
    'recording_id',
    'room_id',
    'call_id',
    'egress_id',
    'file_path',
    'file_name',
    'storage_key',
    'download_url',
    'transcription_enabled',
    'transcript_status',
    'transcript_file_path',
    'transcript_file_name',
    'transcript_size',
    'transcript_error',
    'transcript_completed_at',
    'duration',
    'size',
    'status',
])]
class ConferenceRecording extends Model
{
    /** @use HasFactory<ConferenceRecordingFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'status' => ConferenceRecordingStatus::Starting->value,
    ];

    public function uniqueIds(): array
    {
        return ['recording_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'recording_id';
    }

    public function conferenceRoom(): BelongsTo
    {
        return $this->belongsTo(ConferenceRoom::class);
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(ConferenceRecordingTrack::class);
    }

    public function transcriptSegments(): HasManyThrough
    {
        return $this->hasManyThrough(
            ConferenceRecordingTranscriptSegment::class,
            ConferenceRecordingTrack::class,
        );
    }


    protected function casts(): array
    {
        return [
            'status' => ConferenceRecordingStatus::class,
            'transcript_status' => ConferenceTranscriptStatus::class,
            'transcription_enabled' => 'boolean',
            'duration' => 'integer',
            'size' => 'integer',
            'transcript_size' => 'integer',
            'transcript_completed_at' => 'datetime',
        ];
    }
}
