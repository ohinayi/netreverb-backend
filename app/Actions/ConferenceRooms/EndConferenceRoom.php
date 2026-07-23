<?php

namespace App\Actions\ConferenceRooms;

use App\Enums\ConferenceParticipantStatus;
use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRoom;
use App\Models\User;
use App\Services\ConferenceRecordings\ConferenceRecordingManager;
use App\Services\ConferenceRooms\ConferenceRoomChatService;
use App\Services\ConferenceRooms\ConferenceRoomParticipantPresenceService;
use Illuminate\Support\Facades\DB;

class EndConferenceRoom
{
    public function __construct(
        private ConferenceRecordingManager $recordingManager,
        private ConferenceRoomChatService $conferenceRoomChatService,
        private ConferenceRoomParticipantPresenceService $participantPresenceService,
    ) {}

    public function execute(ConferenceRoom $conferenceRoom, User $user): ConferenceRoom
    {
        $conferenceRoom = DB::transaction(function () use ($conferenceRoom, $user): ConferenceRoom {
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

        $this->recordingManager->stop($conferenceRoom);
        $this->conferenceRoomChatService->clearRoom($conferenceRoom);
        $this->participantPresenceService->clearRoom($conferenceRoom);

        return $conferenceRoom;
    }
}
