<?php

namespace App\Models;

use App\Enums\ConferenceParticipantStatus;
use Database\Factories\ConferenceRoomParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conference_room_id',
    'user_id',
    'display_name',
    'email',
    'role',
    'status',
    'invited_at',
    'joined_at',
    'left_at',
    'metadata',
])]
class ConferenceRoomParticipant extends Model
{
    /** @use HasFactory<ConferenceRoomParticipantFactory> */
    use HasFactory, HasUlids;

    protected $attributes = [
        'status' => ConferenceParticipantStatus::Invited->value,
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function conferenceRoom(): BelongsTo
    {
        return $this->belongsTo(ConferenceRoom::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ConferenceParticipantStatus::class,
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
