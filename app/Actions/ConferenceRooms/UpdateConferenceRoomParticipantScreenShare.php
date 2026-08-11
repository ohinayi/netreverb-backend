<?php

namespace App\Actions\ConferenceRooms;

use Agence104\LiveKit\RoomServiceClient;
use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Enums\ConferenceParticipantKind;
use App\Enums\ConferenceParticipantStatus;
use App\Events\ConferenceRoomScreenShareUpdated;
use App\Exceptions\ConferenceScreenShareAlreadyActiveException;
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

class UpdateConferenceRoomParticipantScreenShare
{
    public function __construct(
        private ConferenceLiveMemberResolver $conferenceLiveMemberResolver,
        private FreeSwitchConferenceGateway $freeSwitchConferenceGateway,
    ) {}

    public function start(ConferenceRoom $conferenceRoom, ConferenceRoomParticipant $participant, User $actor): ConferenceRoomParticipant
    {
        $before = $this->screenShareState($participant->metadata['screen_share'] ?? null);
        $participant = $this->applyMetadata($conferenceRoom, $participant, $actor, 'start');
        $changed = $this->screenShareState($participant->metadata['screen_share'] ?? null) !== $before;

        $screenShareParticipant = $this->joinScreenShareLeg($conferenceRoom, $participant, $actor);
        $participant->setRelation('screenShareParticipant', $screenShareParticipant);

        // The screen INVITE is established asynchronously.  Re-apply the video
        // floor here when it is already visible; the reconciler also retries
        // this while the leg is connecting.
        $this->pinScreenShareFloor($conferenceRoom, $screenShareParticipant);

        if ($changed) {
            ConferenceRoomScreenShareUpdated::dispatch($conferenceRoom, $participant, 'started', 'screen');
        }

        return $participant;
    }

    public function stop(ConferenceRoom $conferenceRoom, ConferenceRoomParticipant $participant, User $actor): ConferenceRoomParticipant
    {
        $before = $this->screenShareState($participant->metadata['screen_share'] ?? null);
        $participant = $this->applyMetadata($conferenceRoom, $participant, $actor, 'stop');
        $changed = $this->screenShareState($participant->metadata['screen_share'] ?? null) !== $before;

        $this->leaveScreenShareLeg($conferenceRoom, $participant);
        $participant->setRelation('screenShareParticipant', null);

        if ($changed) {
            ConferenceRoomScreenShareUpdated::dispatch($conferenceRoom, $participant, 'stopped', 'screen');
        }

        return $participant;
    }

    public function forceStop(ConferenceRoom $conferenceRoom, ConferenceRoomParticipant $participant, User $actor): ConferenceRoomParticipant
    {
        $before = $this->screenShareState($participant->metadata['screen_share'] ?? null);
        $participant = $this->applyMetadata($conferenceRoom, $participant, $actor, 'force_stop');
        $changed = $this->screenShareState($participant->metadata['screen_share'] ?? null) !== $before;

        $this->leaveScreenShareLeg($conferenceRoom, $participant);
        $participant->setRelation('screenShareParticipant', null);

        if ($changed) {
            ConferenceRoomScreenShareUpdated::dispatch($conferenceRoom, $participant, 'blocked', 'screen');
        }

        return $participant;
    }

    public function allow(ConferenceRoom $conferenceRoom, ConferenceRoomParticipant $participant, User $actor): ConferenceRoomParticipant
    {
        $before = $this->screenShareState($participant->metadata['screen_share'] ?? null);
        $participant = $this->applyMetadata($conferenceRoom, $participant, $actor, 'allow');

        if ($this->screenShareState($participant->metadata['screen_share'] ?? null) !== $before) {
            ConferenceRoomScreenShareUpdated::dispatch($conferenceRoom, $participant, 'unblocked', 'screen');
        }

        return $participant;
    }

    /**
     * Apply the requested screen-share metadata transition on the primary participant row.
     * This only ever touches the primary participant's `metadata->screen_share` state; it
     * never talks to FreeSWITCH — screen media now lives entirely on its own leg (see
     * joinScreenShareLeg()/leaveScreenShareLeg()), so the camera leg is never muted/unmuted.
     */
    private function applyMetadata(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
        User $actor,
        string $mode,
    ): ConferenceRoomParticipant {
        return DB::transaction(function () use ($conferenceRoom, $participant, $actor, $mode): ConferenceRoomParticipant {
            $participant = ConferenceRoomParticipant::query()
                ->where('conference_room_id', $conferenceRoom->id)
                ->whereKey($participant->id)
                ->lockForUpdate()
                ->firstOrFail();
            $participant->setRelation('conferenceRoom', $conferenceRoom);

            $screenShare = is_array($participant->metadata['screen_share'] ?? null)
                ? $participant->metadata['screen_share']
                : [];

            $isSharing = (bool) data_get($screenShare, 'is_sharing', data_get($screenShare, 'active', false));
            $isBlockedByHost = (bool) data_get($screenShare, 'blocked_by_host', false);

            if ($mode === 'start') {
                if ($isBlockedByHost) {
                    throw ValidationException::withMessages([
                        'participant' => 'Screen sharing was stopped by the host.',
                    ]);
                }

                $otherParticipantIsSharing = ConferenceRoomParticipant::query()
                    ->where('conference_room_id', $conferenceRoom->id)
                    ->primary()
                    ->whereKeyNot($participant->id)
                    ->where('status', ConferenceParticipantStatus::Joined->value)
                    ->where(function ($query): void {
                        $query->where('metadata->screen_share->is_sharing', true)
                            ->orWhere('metadata->screen_share->active', true);
                    })
                    ->exists();

                if ($otherParticipantIsSharing) {
                    throw ConferenceScreenShareAlreadyActiveException::alreadyActive();
                }

                if ($isSharing) {
                    return $participant->load('user');
                }
            }

            if ($mode === 'stop' && ! $isSharing) {
                return $participant->load('user');
            }

            $now = now()->toIso8601String();
            $screenShare['is_sharing'] = $mode === 'start';
            $screenShare['active'] = $screenShare['is_sharing'];
            $screenShare['updated_at'] = $now;
            $screenShare['updated_by_user_id'] = $actor->public_id;

            if ($mode === 'start') {
                $screenShare['started_at'] = $now;
                $screenShare['stopped_at'] = null;
                $screenShare['stopped_by_user_id'] = null;
                $screenShare['blocked_by_host'] = false;
                $screenShare['blocked_at'] = null;
                $screenShare['blocked_by_user_id'] = null;
            }

            if ($mode === 'stop' || $mode === 'force_stop') {
                $screenShare['stopped_at'] = $isSharing ? $now : data_get($screenShare, 'stopped_at');
                $screenShare['stopped_by_user_id'] = $actor->public_id;
            }

            if ($mode === 'force_stop') {
                $screenShare['blocked_by_host'] = true;
                $screenShare['blocked_at'] = $now;
                $screenShare['blocked_by_user_id'] = $actor->public_id;
            }

            if ($mode === 'allow') {
                $screenShare['blocked_by_host'] = false;
                $screenShare['blocked_at'] = null;
                $screenShare['blocked_by_user_id'] = null;
            }

            $participant->forceFill([
                'status' => $participant->status ?? ConferenceParticipantStatus::Joined,
                'metadata' => [
                    ...($participant->metadata ?? []),
                    'screen_share' => $screenShare,
                ],
            ])->save();

            return $participant->load('user');
        }, attempts: 3);
    }

    /**
     * Create (or reuse) the dedicated screen-share leg row for this participant. The frontend
     * uses the returned row's identity + the room's sip_number to place a second, independent
     * SIP/WebRTC INVITE carrying only the screen capture — the primary (camera/mic) leg is
     * never touched.
     */
    private function joinScreenShareLeg(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
        User $actor,
    ): ConferenceRoomParticipant {
        $screenShareParticipant = ConferenceRoomParticipant::query()->firstOrNew([
            'conference_room_id' => $conferenceRoom->id,
            'user_id' => $participant->user_id,
            'kind' => ConferenceParticipantKind::ScreenShare,
        ]);

        $screenShareParticipant->fill([
            'parent_participant_id' => $participant->id,
            'display_name' => $participant->display_name.ConferenceLiveMemberResolver::SCREEN_SHARE_CALLER_NAME_SUFFIX,
            'email' => $participant->email,
            'role' => $participant->role,
            'status' => ConferenceParticipantStatus::Joined,
            'invited_at' => $screenShareParticipant->exists ? $screenShareParticipant->invited_at : now(),
            'joined_at' => now(),
            'left_at' => null,
        ]);
        $screenShareParticipant->save();

        return $screenShareParticipant->fresh();
    }

    /**
     * Tear down the screen-share leg: kick its live FreeSWITCH member (if connected), release
     * any pinned video floor, and mark the leg row Left. Tolerant of the leg not having
     * connected yet (e.g. the client called stop before the second INVITE was answered).
     */
    private function leaveScreenShareLeg(ConferenceRoom $conferenceRoom, ConferenceRoomParticipant $participant): void
    {
        // LiveKit publishes screen share as a track on the participant's own
        // identity, not a second FreeSWITCH-style leg — mute it unconditionally
        // before the FreeSWITCH-leg lookup below, which only ever matches the
        // old architecture and would otherwise bail out before reaching this.
        $this->muteLiveKitScreenShare($conferenceRoom, $participant);

        $screenShareParticipant = ConferenceRoomParticipant::query()
            ->with('user.extensions.dialableNumber', 'conferenceRoom')
            ->where('parent_participant_id', $participant->id)
            ->where('status', ConferenceParticipantStatus::Joined)
            ->first();

        if ($screenShareParticipant === null) {
            return;
        }

        try {
            $liveMembers = ConferenceControl::rescue(
                fn (): array => $this->conferenceLiveMemberResolver->findMembersForParticipant($screenShareParticipant),
            );

            foreach ($liveMembers as $liveMember) {
                ConferenceControl::rescue(
                    fn (): null => $this->freeSwitchConferenceGateway->kickMember(
                        $conferenceRoom->sip_number,
                        $liveMember['member_id'],
                    ),
                );
            }

            ConferenceControl::rescue(
                fn (): null => $this->freeSwitchConferenceGateway->releaseVideoFloor($conferenceRoom->sip_number),
            );
        } catch (\Throwable $throwable) {
            Log::warning('Failed to tear down conference screen-share leg on FreeSWITCH.', [
                'conference_room_id' => $conferenceRoom->public_id,
                'screen_share_participant_id' => $screenShareParticipant->public_id,
                'error' => $throwable->getMessage(),
            ]);
        }

        $screenShareParticipant->forceFill([
            'status' => ConferenceParticipantStatus::Left,
            'left_at' => now(),
        ])->save();
    }

    private function muteLiveKitScreenShare(ConferenceRoom $conferenceRoom, ConferenceRoomParticipant $participant): void
    {
        $identity = $participant->user?->public_id;
        if ($identity === null) {
            return;
        }

        $roomName = 'netreverb-conference-'.$conferenceRoom->public_id;
        $client = new RoomServiceClient(
            config('livekit.egress_api_url'),
            config('livekit.api_key'),
            config('livekit.api_secret'),
        );

        try {
            $tracks = $client->getParticipant($roomName, 'user-'.$identity)->getTracks();
            foreach ($tracks as $track) {
                if ($track->getSource() === TrackSource::SCREEN_SHARE || $track->getSource() === TrackSource::SCREEN_SHARE_AUDIO) {
                    $client->mutePublishedTrack($roomName, 'user-'.$identity, $track->getSid(), true);
                }
            }
        } catch (Throwable $exception) {
            Log::warning('LiveKit screen-share force-stop failed.', [
                'conference_room_id' => $conferenceRoom->public_id,
                'participant_id' => $participant->public_id,
                'room_name' => $roomName,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function pinScreenShareFloor(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $screenShareParticipant,
    ): void {
        if ($screenShareParticipant->status !== ConferenceParticipantStatus::Joined) {
            return;
        }

        foreach ($this->conferenceLiveMemberResolver->findMembersForParticipant($screenShareParticipant) as $member) {
            $memberId = $member['member_id'] ?? null;
            if (is_string($memberId) && $memberId !== '') {
                $this->freeSwitchConferenceGateway->pinVideoFloor($conferenceRoom->sip_number, $memberId);
                return;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function screenShareState(mixed $screenShareMetadata): array
    {
        if (! is_array($screenShareMetadata)) {
            return [];
        }

        return [
            'is_sharing' => (bool) data_get($screenShareMetadata, 'is_sharing', data_get($screenShareMetadata, 'active', false)),
            'blocked_by_host' => (bool) data_get($screenShareMetadata, 'blocked_by_host', false),
        ];
    }
}
