<?php

namespace App\Console\Commands;

use App\Actions\ConferenceRooms\UpdateConferenceRoomParticipantPresence;
use App\Actions\ConferenceRooms\UpdateConferenceRoomParticipantScreenShare;
use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Enums\ConferenceParticipantStatus;
use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Services\ConferenceRooms\ConferenceRoomParticipantPresenceService;
use App\Services\Telephony\ConferenceLiveMemberResolver;
use Illuminate\Console\Command;

class ReconcileConferenceRoomParticipants extends Command
{
    protected $signature = 'conference-rooms:reconcile-participants';

    protected $description = 'Mark stale joined conference participants as left when they are no longer live in FreeSWITCH.';

    public function handle(
        FreeSwitchConferenceGateway $freeSwitchConferenceGateway,
        ConferenceLiveMemberResolver $conferenceLiveMemberResolver,
        UpdateConferenceRoomParticipantPresence $updateConferenceRoomParticipantPresence,
        ConferenceRoomParticipantPresenceService $participantPresenceService,
        UpdateConferenceRoomParticipantScreenShare $updateConferenceRoomParticipantScreenShare,
    ): int {
        $threshold = now()->subSeconds((int) config('telephony.conference_participants.stale_after_seconds', 90));
        $missesBeforeLeave = max(1, (int) config('telephony.conference_participants.missed_reconciliations_before_leave', 2));
        $updatedCount = 0;

        // Screen sharing starts with a second INVITE and may be requested only
        // seconds after the room joins. Keep pinning independent of the stale
        // participant sweep so the screen leg becomes the remote video floor
        // as soon as FreeSWITCH reports it.
        $screenShareRooms = ConferenceRoom::query()
            ->where('status', ConferenceRoomStatus::Active)
            ->whereHas('participants', fn ($query) => $query
                ->primary()
                ->where('status', ConferenceParticipantStatus::Joined->value)
                ->where(function ($query): void {
                    $query->where('metadata->screen_share->is_sharing', true)
                        ->orWhere('metadata->screen_share->active', true);
                }))
            ->with(['participants' => fn ($query) => $query
                ->primary()
                ->where('status', ConferenceParticipantStatus::Joined->value)
                ->with('screenShareParticipant')])
            ->get();

        foreach ($screenShareRooms as $conferenceRoom) {
            foreach ($conferenceRoom->participants as $participant) {
                if ($participant->screenShareParticipant === null) {
                    continue;
                }

                try {
                    $updateConferenceRoomParticipantScreenShare->pinScreenShareFloor(
                        $conferenceRoom,
                        $participant->screenShareParticipant,
                    );
                } catch (\Throwable $throwable) {
                    report($throwable);
                }
            }
        }

        $rooms = ConferenceRoom::query()
            ->where('status', ConferenceRoomStatus::Active)
            ->whereHas('participants', fn ($query) => $query
                ->where('status', ConferenceParticipantStatus::Joined->value)
                ->where('joined_at', '<=', $threshold))
            ->with([
                'participants' => fn ($query) => $query
                    ->where('status', ConferenceParticipantStatus::Joined->value)
                    ->where('joined_at', '<=', $threshold)
                    ->with(['user.extensions.dialableNumber', 'screenShareParticipant']),
            ])
            ->get();

        foreach ($rooms as $conferenceRoom) {
            $members = $freeSwitchConferenceGateway->listMembers($conferenceRoom->sip_number);

            foreach ($conferenceRoom->participants as $participant) {
                $screenShare = $participant->screenShareParticipant;
                $screenShareActive = (bool) data_get($participant->metadata, 'screen_share.is_sharing', data_get($participant->metadata, 'screen_share.active', false));
                if ($screenShareActive && $screenShare !== null) {
                    try {
                        $updateConferenceRoomParticipantScreenShare->pinScreenShareFloor($conferenceRoom, $screenShare);
                    } catch (\Throwable $throwable) {
                        report($throwable);
                    }
                }

                if ($conferenceLiveMemberResolver->findMemberForParticipant($participant, $members) !== null) {
                    $reconcileState = $this->reconcileState($participant);

                    if (($reconcileState['miss_count'] ?? 0) > 0 || ($reconcileState['last_missing_at'] ?? null) !== null) {
                        $updateConferenceRoomParticipantPresence->execute(
                            $participant,
                            ConferenceParticipantStatus::Joined,
                            null,
                            [
                                'presence_reconcile' => [
                                    'miss_count' => 0,
                                    'last_missing_at' => null,
                                ],
                            ],
                        );

                        $participantPresenceService->touchHeartbeat($participant);
                    }

                    continue;
                }

                // The participant collection is loaded before the gateway
                // call. A host mute can commit its moderation metadata while
                // that call is in flight, so do not make a leave decision from
                // the stale model instance that was loaded at the start of the
                // reconciliation pass.
                $participant->refresh();
                if ($participant->status !== ConferenceParticipantStatus::Joined) {
                    continue;
                }

                // FreeSWITCH can temporarily omit a member from xml_list while
                // a host-level mute/vmute is being applied. More importantly,
                // a participant who is still joined but host-muted must not be
                // converted to `left` merely because the bridge roster no
                // longer exposes the member's caller-id fields. The heartbeat
                // reconciler remains responsible for detecting a real browser
                // disconnect and will transition it to `disconnected`.
                if ($this->isHostModerated($participant)) {
                    $participantPresenceService->touchHeartbeat($participant);

                    continue;
                }

                // Applying a mute/video flag can make mod_conference omit the
                // member for a short interval. Never interpret that transient
                // bridge gap as a leave immediately after moderation.
                $moderation = data_get($participant->metadata, 'moderation');
                $moderatedAt = is_array($moderation)
                    ? ($moderation['audio_muted_at'] ?? $moderation['video_muted_at'] ?? $moderation['audio_unmuted_at'] ?? $moderation['video_unmuted_at'] ?? null)
                    : null;
                if (is_string($moderatedAt) && now()->diffInSeconds($moderatedAt) < 45) {
                    $participantPresenceService->touchHeartbeat($participant);

                    continue;
                }

                $reconcileState = $this->reconcileState($participant);
                $nextMissCount = ($reconcileState['miss_count'] ?? 0) + 1;

                if ($nextMissCount < $missesBeforeLeave) {
                    $updateConferenceRoomParticipantPresence->execute(
                        $participant,
                        ConferenceParticipantStatus::Joined,
                        null,
                        [
                            'presence_reconcile' => [
                                'miss_count' => $nextMissCount,
                                'last_missing_at' => now()->toIso8601String(),
                            ],
                        ],
                    );

                    $participantPresenceService->touchHeartbeat($participant);

                    continue;
                }

                $updateConferenceRoomParticipantPresence->execute(
                    $participant,
                    ConferenceParticipantStatus::Left,
                    now(),
                    [
                        'reconciled_left_at' => now()->toIso8601String(),
                        'presence_reconcile' => [
                            'miss_count' => $nextMissCount,
                            'last_missing_at' => now()->toIso8601String(),
                        ],
                    ],
                );

                $participantPresenceService->clearHeartbeat($participant);

                $updatedCount++;
            }
        }

        $this->info(sprintf('Reconciled %d conference participant(s).', $updatedCount));

        return self::SUCCESS;
    }

    /**
     * @return array{miss_count:int, last_missing_at:?string}
     */
    private function reconcileState(ConferenceRoomParticipant $participant): array
    {
        $metadata = is_array($participant->metadata) ? $participant->metadata : [];
        $reconcileState = is_array($metadata['presence_reconcile'] ?? null)
            ? $metadata['presence_reconcile']
            : [];

        return [
            'miss_count' => max(0, (int) ($reconcileState['miss_count'] ?? 0)),
            'last_missing_at' => is_string($reconcileState['last_missing_at'] ?? null)
                ? $reconcileState['last_missing_at']
                : null,
        ];
    }

    private function isHostModerated(ConferenceRoomParticipant $participant): bool
    {
        $moderation = is_array($participant->metadata)
            ? ($participant->metadata['moderation'] ?? null)
            : null;

        if (! is_array($moderation)) {
            return false;
        }

        return ($moderation['audio_muted_by_host'] ?? false) === true
            || ($moderation['video_muted_by_host'] ?? false) === true;
    }
}
