<?php

namespace App\Actions\ConferenceRooms;

use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Enums\ConferenceParticipantStatus;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\User;
use App\Services\ConferenceRooms\ConferenceRoomParticipantPresenceService;
use App\Services\Telephony\ConferenceLiveMemberResolver;
use App\Support\ConferenceControl;
use Illuminate\Support\Facades\Log;

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
        } catch (\Throwable $throwable) {
            Log::warning('Failed to kick conference participant from FreeSWITCH.', [
                'conference_room_id' => $conferenceRoom->public_id,
                'participant_id' => $participant->public_id,
                'member_ids' => array_column($liveMembers, 'member_id'),
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
