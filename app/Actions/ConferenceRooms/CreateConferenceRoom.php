<?php

namespace App\Actions\ConferenceRooms;

use App\Enums\ConferenceParticipantStatus;
use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateConferenceRoom
{
    public function __construct(
        private AllocateConferenceRoomNumber $allocateConferenceRoomNumber,
    ) {}

    /**
     * @param array{
     *     title: string,
     *     passcode?: ?string,
     *     expires_at?: ?Carbon,
     *     configuration?: ?array<string, mixed>,
     * } $attributes
     */
    public function execute(Organization $organization, User $host, array $attributes): ConferenceRoom
    {
        return DB::transaction(function () use ($organization, $host, $attributes): ConferenceRoom {
            $conferenceRoom = ConferenceRoom::query()->create([
                'organization_id' => $organization->id,
                'host_user_id' => $host->id,
                'room_id' => (string) Str::ulid(),
                'sip_number' => $this->allocateConferenceRoomNumber->execute(),
                'title' => $attributes['title'],
                'status' => ConferenceRoomStatus::Active,
                'passcode_hash' => isset($attributes['passcode']) && $attributes['passcode'] !== null
                    ? Hash::make($attributes['passcode'])
                    : null,
                'expires_at' => $attributes['expires_at']
                    ?? now()->addMinutes(config('telephony.conference_default_duration_minutes')),
                'configuration' => $attributes['configuration'] ?? null,
            ]);

            ConferenceRoomParticipant::query()->create([
                'conference_room_id' => $conferenceRoom->id,
                'user_id' => $host->id,
                'display_name' => $host->name,
                'email' => $host->email,
                'role' => 'host',
                'status' => ConferenceParticipantStatus::Joined,
                'invited_at' => now(),
                'joined_at' => now(),
                'left_at' => null,
                'metadata' => [
                    'is_creator' => true,
                ],
            ]);

            return $conferenceRoom->load(['hostUser', 'participants.user']);
        }, attempts: 3);
    }
}
