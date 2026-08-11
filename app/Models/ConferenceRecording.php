<?php

namespace App\Models;

use App\Enums\ConferenceRecordingStatus;
use Database\Factories\ConferenceRecordingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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


    protected function casts(): array
    {
        return [
            'status' => ConferenceRecordingStatus::class,
            'duration' => 'integer',
            'size' => 'integer',
        ];
    }
}
