<?php

namespace App\Actions\ConferenceRooms;

use App\Enums\ConferenceParticipantStatus;
use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRoom;
use App\Services\ConferenceRecordings\ConferenceRecordingManager;
use App\Services\ConferenceRecordings\LiveKitConferenceRecordingManager;
use App\Services\ConferenceRooms\ConferenceRoomChatService;
use App\Services\ConferenceRooms\ConferenceRoomParticipantPresenceService;
use Illuminate\Support\Carbon;

class TouchConferenceRoomExpiry
{
    public function __construct(
        private ConferenceRecordingManager $recordingManager,
        private LiveKitConferenceRecordingManager $liveKitRecordingManager,
        private ConferenceRoomChatService $conferenceRoomChatService,
        private ConferenceRoomParticipantPresenceService $participantPresenceService,
    ) {}

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
            $this->liveKitRecordingManager->stopActiveForRoom($conferenceRoom);
            $this->conferenceRoomChatService->clearRoom($conferenceRoom);
            $this->participantPresenceService->clearRoom($conferenceRoom);
        }

        return $conferenceRoom;
    }
}
