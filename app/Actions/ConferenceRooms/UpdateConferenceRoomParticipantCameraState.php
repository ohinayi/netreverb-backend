<?php

namespace App\Actions\ConferenceRooms;

use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use Illuminate\Support\Facades\DB;

class UpdateConferenceRoomParticipantCameraState
{
    public function execute(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
        bool $enabled,
    ): ConferenceRoomParticipant {
        return DB::transaction(function () use ($conferenceRoom, $participant, $enabled): ConferenceRoomParticipant {
            $participant = ConferenceRoomParticipant::query()
                ->where('conference_room_id', $conferenceRoom->id)
                ->whereKey($participant->id)
                ->lockForUpdate()
                ->firstOrFail();

            $participant->forceFill([
                'metadata' => [
                    ...($participant->metadata ?? []),
                    'camera' => [
                        'enabled' => $enabled,
                        'updated_at' => now()->toIso8601String(),
                    ],
                ],
            ])->save();

            return $participant->load('user');
        }, attempts: 3);
    }
}
