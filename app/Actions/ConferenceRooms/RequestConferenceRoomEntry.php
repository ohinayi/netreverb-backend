<?php

namespace App\Actions\ConferenceRooms;

use App\Enums\ConferenceParticipantStatus;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class RequestConferenceRoomEntry
{
    public function __construct(private TouchConferenceRoomExpiry $touchConferenceRoomExpiry) {}

    /**
     * @param array{
     *     display_name?: ?string,
     *     passcode?: ?string,
     *     metadata?: ?array<string, mixed>,
     * } $attributes
     */
    public function execute(ConferenceRoom $conferenceRoom, User $user, array $attributes): ConferenceRoomParticipant
    {
        return DB::transaction(function () use ($conferenceRoom, $user, $attributes): ConferenceRoomParticipant {
            $conferenceRoom = ConferenceRoom::query()
                ->lockForUpdate()
                ->findOrFail($conferenceRoom->id);

            $conferenceRoom = $this->touchConferenceRoomExpiry->execute($conferenceRoom);
            $this->ensureRoomCanAcceptEntries($conferenceRoom);

            if ($conferenceRoom->passcode_hash !== null
                && ! Hash::check($attributes['passcode'] ?? '', $conferenceRoom->passcode_hash)) {
                throw ValidationException::withMessages([
                    'passcode' => 'The meeting passcode is invalid.',
                ]);
            }

            $this->expireStaleWaitingParticipants($conferenceRoom);

            $participant = ConferenceRoomParticipant::query()
                ->where('conference_room_id', $conferenceRoom->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($participant === null) {
                $this->ensureWaitingCapacity($conferenceRoom);

                $participant = new ConferenceRoomParticipant([
                    'conference_room_id' => $conferenceRoom->id,
                    'user_id' => $user->id,
                ]);
            }

            if ($participant->status === ConferenceParticipantStatus::Joined) {
                return $participant->load('user');
            }

            if ($participant->status !== ConferenceParticipantStatus::Waiting) {
                $this->ensureWaitingCapacity($conferenceRoom, $participant);
            }

            $participant->fill([
                'display_name' => $attributes['display_name'] ?? $user->name,
                'email' => $user->email,
                'role' => 'participant',
                'status' => ConferenceParticipantStatus::Waiting,
                'invited_at' => now(),
                'joined_at' => null,
                'left_at' => null,
                'metadata' => array_merge($participant->metadata ?? [], $attributes['metadata'] ?? [], [
                    'requested_via_invite' => true,
                    'requested_at' => now()->toIso8601String(),
                ]),
            ]);
            $participant->save();

            return $participant->load('user');
        }, attempts: 3);
    }

    private function ensureRoomCanAcceptEntries(ConferenceRoom $conferenceRoom): void
    {
        if ($conferenceRoom->status->value === 'expired') {
            throw ValidationException::withMessages([
                'conference_room' => 'This meeting invite has expired.',
            ]);
        }

        if ($conferenceRoom->status->value !== 'active') {
            throw ValidationException::withMessages([
                'conference_room' => 'This meeting has ended.',
            ]);
        }
    }

    private function expireStaleWaitingParticipants(ConferenceRoom $conferenceRoom): void
    {
        $conferenceRoom->participants()
            ->where('status', ConferenceParticipantStatus::Waiting->value)
            ->where('invited_at', '<=', now()->subMinutes((int) config('telephony.conference_waiting_room.request_ttl_minutes', 10)))
            ->update([
                'status' => ConferenceParticipantStatus::Left->value,
                'left_at' => now(),
            ]);
    }

    private function ensureWaitingCapacity(
        ConferenceRoom $conferenceRoom,
        ?ConferenceRoomParticipant $existingParticipant = null,
    ): void {
        $pendingCount = $conferenceRoom->participants()
            ->where('status', ConferenceParticipantStatus::Waiting->value)
            ->when(
                $existingParticipant !== null && $existingParticipant->exists,
                fn ($query) => $query->whereKeyNot($existingParticipant->id),
            )
            ->count();

        if ($pendingCount >= (int) config('telephony.conference_waiting_room.max_pending', 25)) {
            throw ValidationException::withMessages([
                'conference_room' => 'The waiting room is full. Please try again shortly.',
            ]);
        }
    }
}
