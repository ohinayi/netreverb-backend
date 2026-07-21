<?php

namespace App\Console\Commands;

use App\Actions\ConferenceRooms\UpdateConferenceRoomParticipantPresence;
use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Enums\ConferenceParticipantStatus;
use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
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
        $missesBeforeLeave = max(1, (int) config('telephony.conference_participants.missed_reconciliations_before_leave', 2));
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
                    $reconcileState = $this->reconcileState($participant);

                    if (($reconcileState['miss_count'] ?? 0) > 0 || ($reconcileState['last_missing_at'] ?? null) !== null) {
                        $updateConferenceRoomParticipantPresence->execute(
                            $participant,
                            ConferenceParticipantStatus::Joined,
                            null,
                            [
                                'presence_reconcile' => [
                                    'miss_count' => 0,
                                    'last_missing_at' => null,
                                ],
                            ],
                        );
                    }

                    continue;
                }

                $reconcileState = $this->reconcileState($participant);
                $nextMissCount = ($reconcileState['miss_count'] ?? 0) + 1;

                if ($nextMissCount < $missesBeforeLeave) {
                    $updateConferenceRoomParticipantPresence->execute(
                        $participant,
                        ConferenceParticipantStatus::Joined,
                        null,
                        [
                            'presence_reconcile' => [
                                'miss_count' => $nextMissCount,
                                'last_missing_at' => now()->toIso8601String(),
                            ],
                        ],
                    );

                    continue;
                }

                $updateConferenceRoomParticipantPresence->execute(
                    $participant,
                    ConferenceParticipantStatus::Left,
                    now(),
                    [
                        'reconciled_left_at' => now()->toIso8601String(),
                        'presence_reconcile' => [
                            'miss_count' => $nextMissCount,
                            'last_missing_at' => now()->toIso8601String(),
                        ],
                    ],
                );

                $updatedCount++;
            }
        }

        $this->info(sprintf('Reconciled %d conference participant(s).', $updatedCount));

        return self::SUCCESS;
    }

    /**
     * @return array{miss_count:int, last_missing_at:?string}
     */
    private function reconcileState(ConferenceRoomParticipant $participant): array
    {
        $metadata = is_array($participant->metadata) ? $participant->metadata : [];
        $reconcileState = is_array($metadata['presence_reconcile'] ?? null)
            ? $metadata['presence_reconcile']
            : [];

        return [
            'miss_count' => max(0, (int) ($reconcileState['miss_count'] ?? 0)),
            'last_missing_at' => is_string($reconcileState['last_missing_at'] ?? null)
                ? $reconcileState['last_missing_at']
                : null,
        ];
    }
}
