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

class LeaveConferenceRoom
{
    public function __construct(
        private UpdateConferenceRoomParticipantPresence $updateConferenceRoomParticipantPresence,
        private ConferenceLiveMemberResolver $conferenceLiveMemberResolver,
        private FreeSwitchConferenceGateway $freeSwitchConferenceGateway,
        private ConferenceRoomParticipantPresenceService $participantPresenceService,
    ) {}

    public function execute(ConferenceRoom $conferenceRoom, User $user): void
    {
        $participant = ConferenceRoomParticipant::query()
            ->with(['user.extensions.dialableNumber', 'conferenceRoom'])
            ->where('conference_room_id', $conferenceRoom->id)
            ->primary()
            ->whereBelongsTo($user)
            ->first();

        if ($participant === null) {
            return;
        }

        $this->kickLiveMembers($conferenceRoom, $participant, $user, 'leave');

        $this->updateConferenceRoomParticipantPresence->execute(
            $participant,
            ConferenceParticipantStatus::Left,
            now(),
            [
                'left_via_api_at' => now()->toIso8601String(),
            ],
        );

        $this->participantPresenceService->clearHeartbeat($participant);

        $screenShareParticipant = ConferenceRoomParticipant::query()
            ->with(['user.extensions.dialableNumber', 'conferenceRoom'])
            ->where('parent_participant_id', $participant->id)
            ->where('status', ConferenceParticipantStatus::Joined)
            ->first();

        if ($screenShareParticipant !== null) {
            $this->kickLiveMembers($conferenceRoom, $screenShareParticipant, $user, 'leave');

            $this->updateConferenceRoomParticipantPresence->execute(
                $screenShareParticipant,
                ConferenceParticipantStatus::Left,
                now(),
                [
                    'left_via_api_at' => now()->toIso8601String(),
                ],
            );

            $this->participantPresenceService->clearHeartbeat($screenShareParticipant);
        }
    }

    private function kickLiveMembers(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
        User $user,
        string $action,
    ): void {
        try {
            $liveMembers = ConferenceControl::rescue(
                fn (): array => $this->conferenceLiveMemberResolver->findMembersForParticipant($participant),
            );

            if ($liveMembers === []) {
                Log::info('Conference leave found no live FreeSWITCH member for participant.', [
                    'conference_room_id' => $conferenceRoom->public_id,
                    'participant_id' => $participant->public_id,
                    'user_id' => $user->public_id,
                    'sip_number' => $conferenceRoom->sip_number,
                    'action' => $action,
                ]);
            }

            foreach ($liveMembers as $liveMember) {
                ConferenceControl::rescue(
                    fn (): null => $this->freeSwitchConferenceGateway->kickMember(
                        $conferenceRoom->sip_number,
                        $liveMember['member_id'],
                    ),
                );
            }
        } catch (\Throwable $throwable) {
            Log::warning('Conference leave could not verify or terminate FreeSWITCH members.', [
                'conference_room_id' => $conferenceRoom->public_id,
                'participant_id' => $participant->public_id,
                'user_id' => $user->public_id,
                'sip_number' => $conferenceRoom->sip_number,
                'action' => $action,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
