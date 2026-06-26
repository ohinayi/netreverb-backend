<?php

namespace App\Actions\ConferenceRooms;

use App\Enums\ConferenceParticipantStatus;
use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\User;
use App\Services\ConferenceRecordings\ConferenceRecordingManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class JoinConferenceRoom
{
    public function __construct(private ConferenceRecordingManager $recordingManager) {}

    /**
     * @param array{
     *     display_name?: ?string,
     *     passcode?: ?string,
     *     metadata?: ?array<string, mixed>,
     * } $attributes
     */
    public function execute(ConferenceRoom $conferenceRoom, User $user, array $attributes): ConferenceRoomParticipant
    {
        $participant = DB::transaction(function () use ($conferenceRoom, $user, $attributes): ConferenceRoomParticipant {
            $conferenceRoom = ConferenceRoom::query()
                ->lockForUpdate()
                ->findOrFail($conferenceRoom->id);

            if ($conferenceRoom->status !== ConferenceRoomStatus::Active) {
                throw ValidationException::withMessages([
                    'conference_room' => 'This meeting is not available for joining.',
                ]);
            }

            if ($conferenceRoom->passcode_hash !== null
                && ! Hash::check($attributes['passcode'] ?? '', $conferenceRoom->passcode_hash)) {
                throw ValidationException::withMessages([
                    'passcode' => 'The meeting passcode is invalid.',
                ]);
            }

            $participant = ConferenceRoomParticipant::query()->firstOrNew([
                'conference_room_id' => $conferenceRoom->id,
                'user_id' => $user->id,
            ]);

            $participant->fill([
                'display_name' => $attributes['display_name'] ?? $user->name,
                'email' => $user->email,
                'role' => $conferenceRoom->host_user_id === $user->id ? 'host' : 'participant',
                'status' => ConferenceParticipantStatus::Joined,
                'invited_at' => $participant->exists ? $participant->invited_at : now(),
                'joined_at' => now(),
                'left_at' => null,
                'metadata' => $attributes['metadata'] ?? $participant->metadata,
            ]);
            $participant->save();

            return $participant->load('user');
        }, attempts: 3);

        $this->recordingManager->start($conferenceRoom);

        return $participant;
    }
}
