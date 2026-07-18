<?php

namespace App\Actions\ConferenceRooms;

use App\Enums\ConferenceParticipantStatus;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Services\ConferenceRecordings\ConferenceRecordingManager;
use Illuminate\Support\Facades\DB;

class UpdateConferenceRoomParticipantPresence
{
    public function __construct(private ConferenceRecordingManager $recordingManager) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function execute(
        ConferenceRoomParticipant $participant,
        ConferenceParticipantStatus $status,
        ?\DateTimeInterface $leftAt = null,
        array $metadata = [],
    ): ConferenceRoomParticipant {
        $shouldStopRecording = false;

        $participant = DB::transaction(function () use ($participant, $status, $leftAt, $metadata, &$shouldStopRecording): ConferenceRoomParticipant {
            $participant = ConferenceRoomParticipant::query()
                ->with('conferenceRoom')
                ->lockForUpdate()
                ->findOrFail($participant->id);

            $participant->forceFill([
                'status' => $status,
                'left_at' => $leftAt,
                'metadata' => array_merge($participant->metadata ?? [], $metadata),
            ])->save();

            $conferenceRoom = ConferenceRoom::query()
                ->lockForUpdate()
                ->findOrFail($participant->conference_room_id);

            $shouldStopRecording = ! $conferenceRoom->participants()
                ->where('status', ConferenceParticipantStatus::Joined->value)
                ->exists();

            return $participant->load(['user', 'conferenceRoom']);
        }, attempts: 3);

        if ($shouldStopRecording) {
            $this->recordingManager->stop($participant->conferenceRoom);
        }

        return $participant;
    }
}
