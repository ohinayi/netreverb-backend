<?php

namespace App\Console\Commands;

use App\Actions\ConferenceRooms\UpdateConferenceRoomParticipantPresence;
use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Enums\ConferenceParticipantStatus;
use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRoom;
use App\Services\Telephony\ConferenceLiveMemberResolver;
use Illuminate\Console\Command;

class ReconcileConferenceRoomParticipants extends Command
{
    protected $signature = 'conference-rooms:reconcile-participants';

    protected $description = 'Mark stale joined conference participants as left when they are no longer live in FreeSWITCH.';

    public function handle(
        FreeSwitchConferenceGateway $freeSwitchConferenceGateway,
        ConferenceLiveMemberResolver $conferenceLiveMemberResolver,
        UpdateConferenceRoomParticipantPresence $updateConferenceRoomParticipantPresence,
    ): int {
        $threshold = now()->subSeconds((int) config('telephony.conference_participants.stale_after_seconds', 90));
        $updatedCount = 0;

        $rooms = ConferenceRoom::query()
            ->where('status', ConferenceRoomStatus::Active)
            ->whereHas('participants', fn ($query) => $query
                ->where('status', ConferenceParticipantStatus::Joined->value)
                ->where('joined_at', '<=', $threshold))
            ->with([
                'participants' => fn ($query) => $query
                    ->where('status', ConferenceParticipantStatus::Joined->value)
                    ->where('joined_at', '<=', $threshold)
                    ->with(['user.extensions.dialableNumber']),
            ])
            ->get();

        foreach ($rooms as $conferenceRoom) {
            $members = $freeSwitchConferenceGateway->listMembers($conferenceRoom->sip_number);

            foreach ($conferenceRoom->participants as $participant) {
                if ($conferenceLiveMemberResolver->findMemberForParticipant($participant, $members) !== null) {
                    continue;
                }

                $updateConferenceRoomParticipantPresence->execute(
                    $participant,
                    ConferenceParticipantStatus::Left,
                    now(),
                    [
                        'reconciled_left_at' => now()->toIso8601String(),
                    ],
                );

                $updatedCount++;
            }
        }

        $this->info(sprintf('Reconciled %d conference participant(s).', $updatedCount));

        return self::SUCCESS;
    }
}
