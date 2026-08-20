<?php

namespace App\Models;

use App\Enums\ConferenceCaptionTrackStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conference_room_id',
    'egress_id',
    'participant_identity',
    'kind',
    'status',
])]
class ConferenceCaptionTrack extends Model
{
    protected $attributes = [
        'status' => ConferenceCaptionTrackStatus::Starting->value,
    ];

    public function conferenceRoom(): BelongsTo
    {
        return $this->belongsTo(ConferenceRoom::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ConferenceCaptionTrackStatus::class,
        ];
    }
}
