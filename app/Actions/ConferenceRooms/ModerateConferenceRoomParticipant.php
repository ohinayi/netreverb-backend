<?php

namespace App\Actions\ConferenceRooms;

use App\Enums\ConferenceParticipantStatus;
use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ModerateConferenceRoomParticipant
{
    public function admit(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
        User $moderator,
    ): ConferenceRoomParticipant {
        return $this->transition(
            $conferenceRoom,
            $participant,
            $moderator,
            ConferenceParticipantStatus::Invited,
            null,
            'admitted',
        );
    }

    public function deny(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
        User $moderator,
    ): ConferenceRoomParticipant {
        return $this->transition(
            $conferenceRoom,
            $participant,
            $moderator,
            ConferenceParticipantStatus::Denied,
            now(),
            'denied',
        );
    }

    private function transition(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
        User $moderator,
        ConferenceParticipantStatus $status,
        mixed $leftAt,
        string $metadataPrefix,
    ): ConferenceRoomParticipant {
        return DB::transaction(function () use (
            $conferenceRoom,
            $participant,
            $moderator,
            $status,
            $leftAt,
            $metadataPrefix,
        ): ConferenceRoomParticipant {
            $conferenceRoom = ConferenceRoom::query()
                ->lockForUpdate()
                ->findOrFail($conferenceRoom->id);

            if ($conferenceRoom->status !== ConferenceRoomStatus::Active) {
                throw ValidationException::withMessages([
                    'conference_room' => 'This meeting is not available.',
                ]);
            }

            $participant = ConferenceRoomParticipant::query()
                ->where('conference_room_id', $conferenceRoom->id)
                ->whereKey($participant->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($participant->status !== ConferenceParticipantStatus::Waiting) {
                throw ValidationException::withMessages([
                    'participant' => 'Only waiting participants can be moderated.',
                ]);
            }

            $participant->forceFill([
                'status' => $status,
                'left_at' => $leftAt,
                'metadata' => array_merge($participant->metadata ?? [], [
                    "{$metadataPrefix}_at" => now()->toIso8601String(),
                    "{$metadataPrefix}_by_user_id" => $moderator->public_id,
                ]),
            ])->save();

            return $participant->load('user');
        }, attempts: 3);
    }
}
