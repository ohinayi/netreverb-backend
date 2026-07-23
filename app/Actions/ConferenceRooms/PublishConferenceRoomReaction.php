<?php

namespace App\Actions\ConferenceRooms;

use App\Enums\ConferenceParticipantStatus;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\ConferenceRoomReaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishConferenceRoomReaction
{
    /**
     * @param  array{expires_in_seconds?: ?int}  $attributes
     */
    public function execute(ConferenceRoom $conferenceRoom, User $actor, array $attributes): ConferenceRoomParticipant|ConferenceRoomReaction
    {
        return DB::transaction(function () use ($conferenceRoom, $actor, $attributes): ConferenceRoomParticipant|ConferenceRoomReaction {
            $participant = ConferenceRoomParticipant::query()
                ->where('conference_room_id', $conferenceRoom->id)
                ->where('user_id', $actor->id)
                ->primary()
                ->lockForUpdate()
                ->first();

            if ($participant === null || ! in_array($participant->status, [ConferenceParticipantStatus::Joined, ConferenceParticipantStatus::Invited], true)) {
                throw ValidationException::withMessages([
                    'participant' => 'You must be connected to the meeting before sending reactions.',
                ]);
            }

            $reactionType = (string) $attributes['reaction_type'];

            if ($reactionType === 'raise_hand' || $reactionType === 'lower_hand') {
                $metadata = $participant->metadata ?? [];
                $reactions = is_array($metadata['reactions'] ?? null) ? $metadata['reactions'] : [];

                $reactions['hand'] = [
                    'raised' => $reactionType === 'raise_hand',
                    'raised_at' => $reactionType === 'raise_hand' ? now()->toIso8601String() : null,
                    'lowered_at' => $reactionType === 'lower_hand' ? now()->toIso8601String() : null,
                    'updated_by_user_id' => $actor->public_id,
                ];

                $participant->forceFill([
                    'metadata' => [
                        ...$metadata,
                        'reactions' => $reactions,
                    ],
                ])->save();

                return $participant->load('user');
            }

            return ConferenceRoomReaction::query()->create([
                'conference_room_id' => $conferenceRoom->id,
                'conference_room_participant_id' => $participant->id,
                'user_id' => $actor->id,
                'reaction_type' => $reactionType,
                'payload' => [
                    'reaction_type' => $reactionType,
                    'created_by_user_id' => $actor->public_id,
                ],
                'expires_at' => now()->addSeconds(max(5, (int) ($attributes['expires_in_seconds'] ?? 30))),
            ])->load(['participant.user', 'user']);
        }, attempts: 3);
    }
}
