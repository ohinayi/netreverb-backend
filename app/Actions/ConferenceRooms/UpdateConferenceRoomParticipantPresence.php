<?php

namespace App\Actions\ConferenceRooms;

use App\Enums\ConferenceParticipantStatus;
use App\Events\ConferenceRoomParticipantPresenceUpdated;
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

            $previousStatus = $participant->status;
            $previousMetadata = $participant->metadata ?? [];

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

            if ($previousStatus !== $status || $previousMetadata !== ($participant->metadata ?? [])) {
                event(new ConferenceRoomParticipantPresenceUpdated([
                    'conference_room_public_id' => $participant->conferenceRoom->public_id,
                    'participant_public_id' => $participant->public_id,
                    'display_name' => $participant->display_name,
                    'status' => $status->value,
                    'last_seen_at' => data_get($metadata, 'presence.last_seen_at'),
                    'left_at' => $leftAt?->format(DATE_ATOM),
                    'disconnected_at' => data_get($metadata, 'presence.disconnected_at'),
                    'reason' => data_get($metadata, 'presence.transition_reason'),
                ]));
            }

            return $participant->load(['user', 'conferenceRoom']);
        }, attempts: 3);

        if ($shouldStopRecording) {
            $this->recordingManager->stop($participant->conferenceRoom);
        }

        return $participant;
    }
}
