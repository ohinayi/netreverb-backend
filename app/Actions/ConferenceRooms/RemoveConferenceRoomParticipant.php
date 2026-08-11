<?php

namespace App\Actions\ConferenceRooms;

use Agence104\LiveKit\RoomServiceClient;
use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Enums\ConferenceParticipantStatus;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\User;
use App\Services\ConferenceRooms\ConferenceRoomParticipantPresenceService;
use App\Services\Telephony\ConferenceLiveMemberResolver;
use App\Support\ConferenceControl;
use Illuminate\Support\Facades\Log;
use Throwable;

class RemoveConferenceRoomParticipant
{
    public function __construct(
        private ConferenceLiveMemberResolver $conferenceLiveMemberResolver,
        private FreeSwitchConferenceGateway $freeSwitchConferenceGateway,
        private UpdateConferenceRoomParticipantPresence $updateConferenceRoomParticipantPresence,
        private ConferenceRoomParticipantPresenceService $participantPresenceService,
    ) {}

    public function execute(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
        User $moderator,
    ): ConferenceRoomParticipant {
        $participant = $participant->loadMissing('user.extensions.dialableNumber', 'conferenceRoom');
        $this->kickLiveMembers($conferenceRoom, $participant);

        $updatedParticipant = $this->updateConferenceRoomParticipantPresence->execute(
            $participant,
            ConferenceParticipantStatus::Removed,
            now(),
            [
                'removed_at' => now()->toIso8601String(),
                'removed_by_user_id' => $moderator->public_id,
            ],
        );

        $this->participantPresenceService->clearHeartbeat($participant);

        $screenShareParticipant = ConferenceRoomParticipant::query()
            ->with('user.extensions.dialableNumber', 'conferenceRoom')
            ->where('parent_participant_id', $participant->id)
            ->where('status', ConferenceParticipantStatus::Joined)
            ->first();

        if ($screenShareParticipant !== null) {
            $this->kickLiveMembers($conferenceRoom, $screenShareParticipant);

            $this->updateConferenceRoomParticipantPresence->execute(
                $screenShareParticipant,
                ConferenceParticipantStatus::Removed,
                now(),
                [
                    'removed_at' => now()->toIso8601String(),
                    'removed_by_user_id' => $moderator->public_id,
                ],
            );

            $this->participantPresenceService->clearHeartbeat($screenShareParticipant);
        }

        return $updatedParticipant;
    }

    private function kickLiveMembers(ConferenceRoom $conferenceRoom, ConferenceRoomParticipant $participant): void
    {
        // LiveKit hosts conference media now; a FreeSWITCH-only kick never
        // disconnects a LiveKit participant, so removal previously only
        // updated the DB status while their session kept streaming.
        $this->removeFromLiveKit($conferenceRoom, $participant);

        $liveMembers = ConferenceControl::rescue(
            fn (): array => $this->conferenceLiveMemberResolver->findMembersForParticipant($participant),
        );

        if ($liveMembers === []) {
            return;
        }

        try {
            foreach ($liveMembers as $liveMember) {
                ConferenceControl::rescue(
                    fn (): null => $this->freeSwitchConferenceGateway->kickMember(
                        $conferenceRoom->sip_number,
                        $liveMember['member_id'],
                    ),
                );
            }
        } catch (Throwable $throwable) {
            Log::warning('Failed to kick conference participant from FreeSWITCH.', [
                'conference_room_id' => $conferenceRoom->public_id,
                'participant_id' => $participant->public_id,
                'member_ids' => array_column($liveMembers, 'member_id'),
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function removeFromLiveKit(ConferenceRoom $conferenceRoom, ConferenceRoomParticipant $participant): void
    {
        $identity = $participant->user?->public_id;
        if ($identity === null) {
            return;
        }

        $roomName = 'netreverb-conference-'.$conferenceRoom->public_id;
        $client = new RoomServiceClient(
            config('livekit.egress_api_url'),
            config('livekit.api_key'),
            config('livekit.api_secret'),
        );

        try {
            $client->removeParticipant($roomName, 'user-'.$identity);
        } catch (Throwable $exception) {
            // Not found / already disconnected is the common, harmless case —
            // still log at warning so a genuine outage is visible.
            Log::warning('Failed to remove conference participant from LiveKit.', [
                'conference_room_id' => $conferenceRoom->public_id,
                'participant_id' => $participant->public_id,
                'room_name' => $roomName,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
