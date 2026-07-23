<?php

namespace App\Console\Commands;

use App\Enums\ConferenceParticipantStatus;
use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRoom;
use App\Services\ConferenceRooms\ConferenceRoomParticipantPresenceService;
use Illuminate\Console\Command;

class ReconcileConferenceRoomPresence extends Command
{
    protected $signature = 'conference-rooms:reconcile-presence';

    protected $description = 'Mark joined conference participants as disconnected when their heartbeat expires.';

    public function handle(ConferenceRoomParticipantPresenceService $participantPresenceService): int
    {
        $rooms = ConferenceRoom::query()
            ->where('status', ConferenceRoomStatus::Active)
            ->whereHas('participants', fn ($query) => $query
                ->where('status', ConferenceParticipantStatus::Joined->value))
            ->with([
                'participants' => fn ($query) => $query
                    ->where('status', ConferenceParticipantStatus::Joined->value)
                    ->with('user'),
            ])
            ->get();

        $updatedCount = 0;

        foreach ($rooms as $conferenceRoom) {
            $updatedCount += $participantPresenceService->reconcileRoom($conferenceRoom);
        }

        $this->info(sprintf('Reconciled presence for %d conference participant(s).', $updatedCount));

        return self::SUCCESS;
    }
}
