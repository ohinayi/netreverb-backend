<?php

namespace App\Actions\ConferenceRooms;

use App\Enums\ConferenceParticipantStatus;
use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRoom;
use App\Services\ConferenceRecordings\ConferenceRecordingManager;
use Illuminate\Support\Carbon;

class TouchConferenceRoomExpiry
{
    public function __construct(private ConferenceRecordingManager $recordingManager) {}

    public function execute(ConferenceRoom $conferenceRoom, ?Carbon $now = null): ConferenceRoom
    {
        $now ??= now();

        if ($conferenceRoom->status === ConferenceRoomStatus::Active
            && $conferenceRoom->expires_at !== null
            && $conferenceRoom->expires_at->isPast()) {
            $conferenceRoom->forceFill([
                'status' => ConferenceRoomStatus::Expired,
                'ended_at' => $now,
            ])->save();

            $conferenceRoom->participants()->update([
                'status' => ConferenceParticipantStatus::Left,
                'left_at' => $now,
            ]);

            $this->recordingManager->stop($conferenceRoom);
        }

        return $conferenceRoom;
    }
}
