<?php

namespace App\Actions\ConferenceRooms;

use App\Enums\ConferenceParticipantStatus;
use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\Organization;
use App\Models\User;
use App\Services\ConferenceRooms\ConferenceRoomParticipantPresenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateConferenceRoom
{
    public function __construct(
        private AllocateConferenceRoomNumber $allocateConferenceRoomNumber,
        private GenerateConferenceRoomInviteCode $generateConferenceRoomInviteCode,
        private ConferenceRoomParticipantPresenceService $participantPresenceService,
    ) {}

    /**
     * @param array{
     *     title: string,
     *     passcode?: ?string,
     *     starts_at?: ?Carbon,
     *     expires_at?: ?Carbon,
     *     duration_minutes?: ?int,
     *     configuration?: ?array<string, mixed>,
     * } $attributes
     */
    public function execute(Organization $organization, User $host, array $attributes): ConferenceRoom
    {
        $conferenceRoom = DB::transaction(function () use ($organization, $host, $attributes): ConferenceRoom {
            $startsAt = $attributes['starts_at'] ?? now();
            $expiresAt = $attributes['expires_at']
                ?? $startsAt->copy()->addMinutes(
                    (int) ($attributes['duration_minutes'] ?? config('telephony.conference_default_duration_minutes')),
                );

            $conferenceRoom = ConferenceRoom::query()->create([
                'organization_id' => $organization->id,
                'host_user_id' => $host->id,
                'room_id' => (string) Str::ulid(),
                'invite_code' => $this->generateConferenceRoomInviteCode->execute(),
                'sip_number' => $this->allocateConferenceRoomNumber->execute(),
                'title' => $attributes['title'],
                'status' => ConferenceRoomStatus::Active,
                'passcode_hash' => isset($attributes['passcode']) && $attributes['passcode'] !== null
                    ? Hash::make($attributes['passcode'])
                    : null,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'configuration' => $this->normalizeConfiguration($attributes['configuration'] ?? null),
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

            return $conferenceRoom->load(['organization', 'hostUser', 'participants.user']);
        }, attempts: 3);

        $this->participantPresenceService->touchHeartbeat(
            $conferenceRoom->participants()->whereBelongsTo($host)->sole(),
        );

        return $conferenceRoom;
    }

    /**
     * @param  array<string, mixed>|null  $configuration
     * @return array<string, mixed>
     */
    private function normalizeConfiguration(?array $configuration): array
    {
        $configuration ??= [];
        $configuration['is_open'] = (bool) ($configuration['is_open'] ?? false);

        return $configuration;
    }
}
