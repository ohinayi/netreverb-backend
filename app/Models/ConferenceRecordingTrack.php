<?php

namespace App\Models;

use App\Enums\ConferenceRecordingTrackStatus;
use App\Enums\ConferenceTranscriptStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'conference_recording_id',
    'egress_id',
    'participant_identity',
    'kind',
    'storage_key',
    'status',
    'transcript_status',
    'transcript_error',
    'transcript_started_at',
    'transcript_completed_at',
    'duration',
    'size',
])]
class ConferenceRecordingTrack extends Model
{
    protected $attributes = [
        'status' => ConferenceRecordingTrackStatus::Starting->value,
    ];

    public function conferenceRecording(): BelongsTo
    {
        return $this->belongsTo(ConferenceRecording::class);
    }

    public function transcriptSegments(): HasMany
    {
        return $this->hasMany(ConferenceRecordingTranscriptSegment::class, 'conference_recording_track_id');
    }

    /**
     * LiveKit's plain StartTrackEgress request has no field to disable this
     * (that option only exists on the unrelated AutoTrackEgress request type)
     * — every raw track upload silently gets a sibling `{egress_id}.json`
     * manifest written next to it that we never asked for and never read.
     * We know exactly where it landed (same directory, named by egress_id),
     * so cleanup can remove it even though we never stored its path.
     */
    public function manifestStorageKey(): ?string
    {
        if (! $this->storage_key || ! $this->egress_id) {
            return null;
        }

        return dirname($this->storage_key).'/'.$this->egress_id.'.json';
    }

    protected function casts(): array
    {
        return [
            'status' => ConferenceRecordingTrackStatus::class,
            'transcript_status' => ConferenceTranscriptStatus::class,
            'duration' => 'integer',
            'size' => 'integer',
            'transcript_started_at' => 'datetime',
            'transcript_completed_at' => 'datetime',
        ];
    }
}
