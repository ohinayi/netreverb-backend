<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conference_recording_track_id',
    'segment_index',
    'start_ms',
    'end_ms',
    'text',
])]
class ConferenceRecordingTranscriptSegment extends Model
{
    public function track(): BelongsTo
    {
        return $this->belongsTo(ConferenceRecordingTrack::class, 'conference_recording_track_id');
    }

    protected function casts(): array
    {
        return [
            'segment_index' => 'integer',
            'start_ms' => 'integer',
            'end_ms' => 'integer',
        ];
    }
}
