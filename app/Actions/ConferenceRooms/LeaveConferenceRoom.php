<?php

namespace App\Actions\ConferenceRooms;

use App\Enums\ConferenceParticipantStatus;
use App\Models\ConferenceRoom;
use App\Models\User;
use App\Services\ConferenceRecordings\ConferenceRecordingManager;
use Illuminate\Support\Facades\DB;

class LeaveConferenceRoom
{
    public function __construct(private ConferenceRecordingManager $recordingManager) {}

    public function execute(ConferenceRoom $conferenceRoom, User $user): void
    {
        $shouldStopRecording = false;

        DB::transaction(function () use ($conferenceRoom, $user, &$shouldStopRecording): void {
            $conferenceRoom = ConferenceRoom::query()
                ->lockForUpdate()
                ->findOrFail($conferenceRoom->id);

            $participant = $conferenceRoom->participants()
                ->whereBelongsTo($user)
                ->lockForUpdate()
                ->first();

            if ($participant === null) {
                return;
            }

            $participant->forceFill([
                'status' => ConferenceParticipantStatus::Left,
                'left_at' => now(),
            ])->save();

            $shouldStopRecording = ! $conferenceRoom->participants()
                ->where('status', ConferenceParticipantStatus::Joined->value)
                ->exists();
        }, attempts: 3);

        if ($shouldStopRecording) {
            $this->recordingManager->stop($conferenceRoom);
        }
    }
}
