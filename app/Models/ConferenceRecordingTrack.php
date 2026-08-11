<?php

namespace App\Models;

use App\Enums\ConferenceRecordingTrackStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conference_recording_id',
    'egress_id',
    'participant_identity',
    'kind',
    'storage_key',
    'status',
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
            'duration' => 'integer',
            'size' => 'integer',
        ];
    }
}
