<?php

namespace App\Actions\ConferenceRooms;

use App\Enums\ConferenceParticipantStatus;
use App\Models\ConferenceRoom;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LeaveConferenceRoom
{
    public function execute(ConferenceRoom $conferenceRoom, User $user): void
    {
        DB::transaction(function () use ($conferenceRoom, $user): void {
            $participant = $conferenceRoom->participants()
                ->whereBelongsTo($user)
                ->lockForUpdate()
                ->first();

            if ($participant === null) {
                return;
            }

            $participant->forceFill([
                'status' => ConferenceParticipantStatus::Left,
                'left_at' => now(),
            ])->save();
        }, attempts: 3);
    }
}
