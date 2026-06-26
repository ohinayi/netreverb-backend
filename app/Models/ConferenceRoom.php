<?php

namespace App\Models;

use App\Enums\ConferenceRoomStatus;
use Database\Factories\ConferenceRoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'host_user_id',
    'room_id',
    'sip_number',
    'title',
    'status',
    'passcode_hash',
    'expires_at',
    'ended_at',
    'ended_by_user_id',
    'configuration',
])]
#[Hidden(['passcode_hash'])]
class ConferenceRoom extends Model
{
    /** @use HasFactory<ConferenceRoomFactory> */
    use HasFactory, HasUlids;

    protected $attributes = [
        'status' => ConferenceRoomStatus::Active->value,
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function hostUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function endedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConferenceRoomParticipant::class);
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(ConferenceRecording::class);
    }

    public function conferenceRecordings(): HasMany
    {
        return $this->recordings();
    }

    protected function casts(): array
    {
        return [
            'status' => ConferenceRoomStatus::class,
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
            'configuration' => 'array',
        ];
    }
}
