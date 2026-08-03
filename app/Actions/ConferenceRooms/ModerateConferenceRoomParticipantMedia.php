<?php

namespace App\Actions\ConferenceRooms;

use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Exceptions\ConferenceControlUnavailableException;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\User;
use App\Services\Telephony\ConferenceLiveMemberResolver;
use App\Support\ConferenceControl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ModerateConferenceRoomParticipantMedia
{
    public function __construct(
        private ConferenceLiveMemberResolver $conferenceLiveMemberResolver,
        private FreeSwitchConferenceGateway $freeSwitchConferenceGateway,
        private UpdateConferenceRoomParticipantPresence $updateConferenceRoomParticipantPresence,
    ) {}

    public function muteAudio(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
        User $moderator,
    ): ConferenceRoomParticipant {
        return $this->apply(
            $conferenceRoom,
            $participant,
            $moderator,
            function (string $conferenceName, string $memberId): void {
                $this->freeSwitchConferenceGateway->muteMember($conferenceName, $memberId);
            },
            fn (array $moderation): array => [
                ...$moderation,
                'audio_muted_by_host' => true,
                'audio_muted_at' => now()->toIso8601String(),
                'audio_muted_by_user_id' => $moderator->public_id,
            ],
        );
    }

    public function unmuteAudio(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
        User $moderator,
    ): ConferenceRoomParticipant {
        return $this->apply(
            $conferenceRoom,
            $participant,
            $moderator,
            function (string $conferenceName, string $memberId): void {
                $this->freeSwitchConferenceGateway->unmuteMember($conferenceName, $memberId);
            },
            fn (array $moderation): array => [
                ...$moderation,
                'audio_muted_by_host' => false,
                'audio_unmuted_at' => now()->toIso8601String(),
                'audio_unmuted_by_user_id' => $moderator->public_id,
            ],
        );
    }

    public function muteVideo(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
        User $moderator,
    ): ConferenceRoomParticipant {
        return $this->apply(
            $conferenceRoom,
            $participant,
            $moderator,
            function (string $conferenceName, string $memberId): void {
                $this->freeSwitchConferenceGateway->videoMuteMember($conferenceName, $memberId);
            },
            fn (array $moderation): array => [
                ...$moderation,
                'video_muted_by_host' => true,
                'video_muted_at' => now()->toIso8601String(),
                'video_muted_by_user_id' => $moderator->public_id,
            ],
        );
    }

    public function unmuteVideo(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
        User $moderator,
    ): ConferenceRoomParticipant {
        return $this->apply(
            $conferenceRoom,
            $participant,
            $moderator,
            function (string $conferenceName, string $memberId): void {
                $this->freeSwitchConferenceGateway->videoUnmuteMember($conferenceName, $memberId);
            },
            fn (array $moderation): array => [
                ...$moderation,
                'video_muted_by_host' => false,
                'video_unmuted_at' => now()->toIso8601String(),
                'video_unmuted_by_user_id' => $moderator->public_id,
            ],
        );
    }

    /**
     * @param  callable(string, string): void  $command
     * @param  callable(array<string, mixed>): array<string, mixed>  $updateModeration
     */
    private function apply(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
        User $moderator,
        callable $command,
        callable $updateModeration,
    ): ConferenceRoomParticipant {
        $participant = $participant->loadMissing('user.extensions.dialableNumber', 'conferenceRoom');
        $roomMembers = ConferenceControl::rescue(
            fn (): array => $this->conferenceLiveMemberResolver->membersForRoom($conferenceRoom),
        );
        $liveMember = $this->conferenceLiveMemberResolver->findMemberForParticipant($participant, $roomMembers);

        if ($liveMember === null) {
            Log::warning('Conference moderation could not match participant to a live FreeSWITCH member.', [
                'conference_room_id' => $conferenceRoom->public_id,
                'participant_id' => $participant->public_id,
                'moderator_id' => $moderator->public_id,
                'sip_number' => $conferenceRoom->sip_number,
                'participant_status' => $participant->status?->value ?? $participant->status,
                'expected_numbers' => $participant->user?->extensions
                    ?->pluck('dialableNumber.number')
                    ->filter(static fn (?string $number): bool => is_string($number) && $number !== '')
                    ->values()
                    ->all() ?? [],
                'expected_names' => array_values(array_filter([
                    $participant->display_name,
                    $participant->user?->name,
                    $participant->email,
                    $participant->user?->email,
                ], static fn (?string $value): bool => is_string($value) && trim($value) !== '')),
                'live_members' => $roomMembers,
                'action' => 'moderation',
            ]);

            throw ValidationException::withMessages([
                'participant' => 'This participant is not actively connected to the conference.',
            ]);
        }

        if (trim((string) ($liveMember['member_id'] ?? '')) === '') {
            Log::warning('Conference moderation found a live participant but no usable FreeSWITCH member id.', [
                'conference_room_id' => $conferenceRoom->public_id,
                'participant_id' => $participant->public_id,
                'moderator_id' => $moderator->public_id,
                'sip_number' => $conferenceRoom->sip_number,
                'live_member' => $liveMember,
                'action' => 'moderation',
            ]);

            throw ConferenceControlUnavailableException::conferenceRosterUnavailable();
        }

        ConferenceControl::rescue(
            fn (): null => $command($conferenceRoom->sip_number, $liveMember['member_id']),
        );

        // A media moderation command must never change conference presence.
        // The bridge can briefly omit a member while applying mute/video flags;
        // preserve the joined status and reset any stale reconciliation misses.

        return DB::transaction(function () use ($participant, $updateModeration): ConferenceRoomParticipant {
            $participant = ConferenceRoomParticipant::query()
                ->with('user')
                ->lockForUpdate()
                ->findOrFail($participant->id);

            $metadata = $participant->metadata ?? [];
            $moderation = is_array($metadata['moderation'] ?? null) ? $metadata['moderation'] : [];
            $metadata['moderation'] = $updateModeration($moderation);
            $metadata['presence_reconcile'] = [
                'miss_count' => 0,
                'last_missing_at' => null,
            ];

            $participant->forceFill([
                'metadata' => $metadata,
            ])->save();

            return $participant;
        }, attempts: 3);
    }
}
