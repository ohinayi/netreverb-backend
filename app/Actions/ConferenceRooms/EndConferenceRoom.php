<?php

namespace App\Actions\ConferenceRooms;

use Agence104\LiveKit\RoomServiceClient;
use App\Enums\ConferenceParticipantStatus;
use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRoom;
use App\Models\User;
use App\Services\ConferenceRecordings\LiveKitConferenceRecordingManager;
use App\Services\ConferenceRooms\ConferenceRoomChatService;
use App\Services\ConferenceRooms\ConferenceRoomParticipantPresenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class EndConferenceRoom
{
    public function __construct(
        private LiveKitConferenceRecordingManager $liveKitRecordingManager,
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

        $this->liveKitRecordingManager->stopActiveForRoom($conferenceRoom);
        $this->conferenceRoomChatService->clearRoom($conferenceRoom);
        $this->participantPresenceService->clearRoom($conferenceRoom);
        $this->deleteLiveKitRoom($conferenceRoom);

        return $conferenceRoom;
    }

    /**
     * Marking the room Ended in the DB does not disconnect anyone still
     * streaming — each client only learns about it on its own next poll or
     * action. Deleting the LiveKit room force-disconnects every connected
     * participant's live media immediately, which is what "end for all"
     * actually requires.
     */
    private function deleteLiveKitRoom(ConferenceRoom $conferenceRoom): void
    {
        $roomName = 'netreverb-conference-'.$conferenceRoom->public_id;
        $client = new RoomServiceClient(
            config('livekit.egress_api_url'),
            config('livekit.api_key'),
            config('livekit.api_secret'),
        );

        try {
            $client->deleteRoom($roomName);
        } catch (Throwable $exception) {
            // Room already empty/gone is the common, harmless case — still
            // log at warning so a genuine LiveKit outage stays visible.
            Log::warning('Failed to delete LiveKit room while ending conference for all.', [
                'conference_room_id' => $conferenceRoom->public_id,
                'room_name' => $roomName,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
