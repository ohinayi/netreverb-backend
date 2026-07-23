<?php

namespace App\Models;

use App\Enums\ConferenceParticipantKind;
use App\Enums\ConferenceParticipantStatus;
use Database\Factories\ConferenceRoomParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'conference_room_id',
    'user_id',
    'parent_participant_id',
    'display_name',
    'email',
    'role',
    'kind',
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
        'kind' => ConferenceParticipantKind::Primary->value,
    ];

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parentParticipant(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_participant_id');
    }

    public function screenShareParticipant(): HasOne
    {
        return $this->hasOne(self::class, 'parent_participant_id')
            ->where('kind', ConferenceParticipantKind::ScreenShare);
    }

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('kind', ConferenceParticipantKind::Primary);
    }

    protected function casts(): array
    {
        return [
            'status' => ConferenceParticipantStatus::class,
            'kind' => ConferenceParticipantKind::class,
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
