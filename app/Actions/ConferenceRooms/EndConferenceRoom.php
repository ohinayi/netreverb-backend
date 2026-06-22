<?php

namespace App\Actions\ConferenceRooms;

use App\Enums\ConferenceParticipantStatus;
use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRoom;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EndConferenceRoom
{
    public function execute(ConferenceRoom $conferenceRoom, User $user): ConferenceRoom
    {
        return DB::transaction(function () use ($conferenceRoom, $user): ConferenceRoom {
            $conferenceRoom = ConferenceRoom::query()
                ->with('participants')
                ->lockForUpdate()
                ->findOrFail($conferenceRoom->id);

            $conferenceRoom->forceFill([
                'status' => ConferenceRoomStatus::Ended,
                'ended_at' => now(),
                'ended_by_user_id' => $user->id,
            ])->save();

            $conferenceRoom->participants()->update([
                'status' => ConferenceParticipantStatus::Left,
                'left_at' => now(),
            ]);

            return $conferenceRoom->fresh(['hostUser', 'participants.user', 'endedByUser']);
        }, attempts: 3);
    }
}
