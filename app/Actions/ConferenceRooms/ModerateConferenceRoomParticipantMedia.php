<?php

namespace App\Actions\ConferenceRooms;

use Agence104\LiveKit\RoomServiceClient;
use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\User;
use App\Services\Telephony\ConferenceLiveMemberResolver;
use App\Support\ConferenceControl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livekit\TrackSource;
use Throwable;

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
            TrackSource::MICROPHONE,
            true,
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
            TrackSource::MICROPHONE,
            false,
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
            TrackSource::CAMERA,
            true,
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
            TrackSource::CAMERA,
            false,
            fn (array $moderation): array => [
                ...$moderation,
                'video_muted_by_host' => false,
                'video_unmuted_at' => now()->toIso8601String(),
                'video_unmuted_by_user_id' => $moderator->public_id,
            ],
        );
    }

    /**
     * Conference media moved from FreeSWITCH to LiveKit; this action was never
     * updated and always failed to find a "live FreeSWITCH member" for a
     * LiveKit-hosted participant, throwing "not actively connected" on every
     * mute/video-off attempt. Try FreeSWITCH first (covers any room still on
     * the old bridge), then fall back to LiveKit's RoomServiceClient — only
     * throw if the participant isn't live on either.
     *
     * @param  callable(string, string): void  $freeSwitchCommand
     * @param  callable(array<string, mixed>): array<string, mixed>  $updateModeration
     */
    private function apply(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
        User $moderator,
        callable $freeSwitchCommand,
        int $trackSource,
        bool $muted,
        callable $updateModeration,
    ): ConferenceRoomParticipant {
        $participant = $participant->loadMissing('user.extensions.dialableNumber', 'conferenceRoom');
        $roomMembers = ConferenceControl::rescue(
            fn (): array => $this->conferenceLiveMemberResolver->membersForRoom($conferenceRoom),
        );
        $liveMember = $this->conferenceLiveMemberResolver->findMemberForParticipant($participant, $roomMembers);

        if ($liveMember !== null && trim((string) ($liveMember['member_id'] ?? '')) !== '') {
            ConferenceControl::rescue(
                fn (): null => $freeSwitchCommand($conferenceRoom->sip_number, $liveMember['member_id']),
            );
        } elseif (! $this->applyViaLiveKit($conferenceRoom, $participant, $trackSource, $muted)) {
            Log::warning('Conference moderation could not match participant to a live FreeSWITCH member or LiveKit track.', [
                'conference_room_id' => $conferenceRoom->public_id,
                'participant_id' => $participant->public_id,
                'moderator_id' => $moderator->public_id,
                'sip_number' => $conferenceRoom->sip_number,
                'participant_status' => $participant->status?->value ?? $participant->status,
                'track_source' => $trackSource,
                'action' => 'moderation',
            ]);

            throw ValidationException::withMessages([
                'participant' => 'This participant is not actively connected to the conference.',
            ]);
        }

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

    private function applyViaLiveKit(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
        int $trackSource,
        bool $muted,
    ): bool {
        $identity = $participant->user?->public_id;
        if ($identity === null) {
            return false;
        }

        $roomName = 'netreverb-conference-'.$conferenceRoom->public_id;
        $client = new RoomServiceClient(
            config('livekit.egress_api_url'),
            config('livekit.api_key'),
            config('livekit.api_secret'),
        );

        try {
            // $trackSource must stay typed int here — it used to be typed
            // string, which silently coerced the TrackSource::* int constant
            // callers pass in, making this strict !== always true (int vs
            // string) and this fallback always report "no matching track".
            $tracks = $client->getParticipant($roomName, 'user-'.$identity)->getTracks();
            foreach ($tracks as $track) {
                if ($track->getSource() !== $trackSource) {
                    continue;
                }

                $client->mutePublishedTrack($roomName, 'user-'.$identity, $track->getSid(), $muted);

                // Muting stops the track's data from reaching other people,
                // but gives the muted participant's own client no way to
                // know *why* their mic/camera went quiet, or that it was the
                // host and not a network glitch. Attributes are LiveKit's
                // own realtime channel (no separate websocket infra needed
                // here) — the affected client listens for
                // ParticipantAttributesChanged on itself to show that state.
                $attributeKey = $trackSource === TrackSource::CAMERA
                    ? 'moderation_video_muted'
                    : 'moderation_audio_muted';
                $client->updateParticipant(
                    $roomName,
                    'user-'.$identity,
                    attributes: [$attributeKey => $muted ? '1' : ''],
                );

                return true;
            }

            return false;
        } catch (Throwable $exception) {
            Log::warning('LiveKit moderation fallback failed.', [
                'conference_room_id' => $conferenceRoom->public_id,
                'participant_id' => $participant->public_id,
                'room_name' => $roomName,
                'track_source' => $trackSource,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
