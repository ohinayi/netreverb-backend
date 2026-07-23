<?php

namespace App\Models;

use Database\Factories\ConferenceRoomReactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conference_room_id',
    'conference_room_participant_id',
    'user_id',
    'reaction_type',
    'payload',
    'expires_at',
])]
class ConferenceRoomReaction extends Model
{
    /** @use HasFactory<ConferenceRoomReactionFactory> */
    use HasFactory, HasUlids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function conferenceRoom(): BelongsTo
    {
        return $this->belongsTo(ConferenceRoom::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ConferenceRoomParticipant::class, 'conference_room_participant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
