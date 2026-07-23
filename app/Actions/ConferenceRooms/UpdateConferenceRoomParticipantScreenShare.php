<?php

namespace App\Actions\ConferenceRooms;

use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Enums\ConferenceParticipantStatus;
use App\Events\ConferenceRoomScreenShareUpdated;
use App\Exceptions\ConferenceControlUnavailableException;
use App\Exceptions\ConferenceScreenShareAlreadyActiveException;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\User;
use App\Services\Telephony\ConferenceLiveMemberResolver;
use App\Support\ConferenceControl;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateConferenceRoomParticipantScreenShare
{
    public function __construct(
        private ConferenceLiveMemberResolver $conferenceLiveMemberResolver,
        private FreeSwitchConferenceGateway $freeSwitchConferenceGateway,
    ) {}

    public function start(ConferenceRoom $conferenceRoom, ConferenceRoomParticipant $participant, User $actor): ConferenceRoomParticipant
    {
        $before = $this->screenShareState($participant->metadata['screen_share'] ?? null);
        $participant = $this->apply($conferenceRoom, $participant, $actor, 'start');

        if ($this->screenShareState($participant->metadata['screen_share'] ?? null) !== $before) {
            ConferenceRoomScreenShareUpdated::dispatch($conferenceRoom, $participant, 'started', 'screen');
        }

        return $participant;
    }

    public function stop(ConferenceRoom $conferenceRoom, ConferenceRoomParticipant $participant, User $actor): ConferenceRoomParticipant
    {
        $before = $this->screenShareState($participant->metadata['screen_share'] ?? null);
        $participant = $this->apply($conferenceRoom, $participant, $actor, 'stop');

        if ($this->screenShareState($participant->metadata['screen_share'] ?? null) !== $before) {
            ConferenceRoomScreenShareUpdated::dispatch($conferenceRoom, $participant, 'stopped', 'screen');
        }

        return $participant;
    }

    public function forceStop(ConferenceRoom $conferenceRoom, ConferenceRoomParticipant $participant, User $actor): ConferenceRoomParticipant
    {
        $before = $this->screenShareState($participant->metadata['screen_share'] ?? null);
        $participant = $this->apply($conferenceRoom, $participant, $actor, 'force_stop');

        if ($this->screenShareState($participant->metadata['screen_share'] ?? null) !== $before) {
            ConferenceRoomScreenShareUpdated::dispatch($conferenceRoom, $participant, 'blocked', 'screen');
        }

        return $participant;
    }

    public function allow(ConferenceRoom $conferenceRoom, ConferenceRoomParticipant $participant, User $actor): ConferenceRoomParticipant
    {
        $before = $this->screenShareState($participant->metadata['screen_share'] ?? null);
        $participant = $this->apply($conferenceRoom, $participant, $actor, 'allow');

        if ($this->screenShareState($participant->metadata['screen_share'] ?? null) !== $before) {
            ConferenceRoomScreenShareUpdated::dispatch($conferenceRoom, $participant, 'unblocked', 'screen');
        }

        return $participant;
    }

    private function apply(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
        User $actor,
        string $mode,
    ): ConferenceRoomParticipant {
        $participant = $participant->loadMissing('user.extensions.dialableNumber', 'conferenceRoom');
        $roomMembers = ConferenceControl::rescue(
            fn (): array => $this->conferenceLiveMemberResolver->membersForRoom($conferenceRoom),
        );
        $liveMember = $this->conferenceLiveMemberResolver->findMemberForParticipant($participant, $roomMembers);

        if ($liveMember === null) {
            throw ValidationException::withMessages([
                'participant' => 'This participant is not actively connected to the conference.',
            ]);
        }

        if (trim((string) ($liveMember['member_id'] ?? '')) === '') {
            throw ConferenceControlUnavailableException::conferenceRosterUnavailable();
        }

        return DB::transaction(function () use (
            $conferenceRoom,
            $participant,
            $actor,
            $mode,
            $liveMember,
        ): ConferenceRoomParticipant {
            $roomParticipants = ConferenceRoomParticipant::query()
                ->where('conference_room_id', $conferenceRoom->id)
                ->lockForUpdate()
                ->get();

            $participant = ConferenceRoomParticipant::query()
                ->where('conference_room_id', $conferenceRoom->id)
                ->whereKey($participant->id)
                ->lockForUpdate()
                ->firstOrFail();

            $screenShare = is_array($participant->metadata['screen_share'] ?? null)
                ? $participant->metadata['screen_share']
                : [];

            $isSharing = (bool) data_get($screenShare, 'is_sharing', data_get($screenShare, 'active', false));
            $isBlockedByHost = (bool) data_get($screenShare, 'blocked_by_host', false);
            $otherParticipantIsSharing = ConferenceRoomParticipant::query()
                ->where('conference_room_id', $conferenceRoom->id)
                ->whereKeyNot($participant->id)
                ->where('status', ConferenceParticipantStatus::Joined->value)
                ->where(function ($query): void {
                    $query->where('metadata->screen_share->is_sharing', true)
                        ->orWhere('metadata->screen_share->active', true);
                })
                ->exists();

            if ($mode === 'start') {
                if ($isBlockedByHost) {
                    throw ValidationException::withMessages([
                        'participant' => 'Screen sharing was stopped by the host.',
                    ]);
                }

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

            $this->applyBridgeCommand($conferenceRoom->sip_number, (string) $liveMember['member_id'], $mode, $isSharing);

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
                $screenShare['is_sharing'] = false;
                $screenShare['active'] = false;
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

    private function applyBridgeCommand(
        string $conferenceName,
        string $memberId,
        string $mode,
        bool $isSharing,
    ): void {
        if ($mode === 'start') {
            ConferenceControl::rescue(
                fn (): null => $this->freeSwitchConferenceGateway->videoUnmuteMember($conferenceName, $memberId),
            );

            return;
        }

        if (($mode === 'stop' || $mode === 'force_stop') && ! $isSharing) {
            return;
        }

        if ($mode === 'allow') {
            return;
        }

        ConferenceControl::rescue(
            fn (): null => $this->freeSwitchConferenceGateway->videoMuteMember($conferenceName, $memberId),
        );
    }

    private function isSharing(mixed $screenShareMetadata): bool
    {
        if (! is_array($screenShareMetadata)) {
            return false;
        }

        return (bool) data_get($screenShareMetadata, 'is_sharing', data_get($screenShareMetadata, 'active', false));
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
