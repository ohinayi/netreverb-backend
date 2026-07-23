<?php

namespace App\Services\ConferenceRooms;

use App\Actions\ConferenceRooms\UpdateConferenceRoomParticipantPresence;
use App\Enums\ConferenceParticipantStatus;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ConferenceRoomParticipantPresenceService
{
    public function __construct(
        private UpdateConferenceRoomParticipantPresence $updateConferenceRoomParticipantPresence,
    ) {}

    public function touchHeartbeat(
        ConferenceRoomParticipant $participant,
        ?Carbon $seenAt = null,
    ): ConferenceRoomParticipant {
        $seenAt ??= now()->utc();

        Cache::put(
            $this->heartbeatKey($participant),
            [
                'last_seen_at' => $seenAt->toIso8601ZuluString(),
                'heartbeat_interval_seconds' => $this->heartbeatIntervalSeconds(),
                'timeout_seconds' => $this->timeoutSeconds(),
            ],
            now()->addSeconds($this->cacheTtlSeconds()),
        );

        return $participant;
    }

    public function clearHeartbeat(ConferenceRoomParticipant $participant): void
    {
        Cache::forget($this->heartbeatKey($participant));
    }

    public function clearRoom(ConferenceRoom $conferenceRoom): void
    {
        foreach ($conferenceRoom->participants()->select(['public_id', 'conference_room_id'])->get() as $participant) {
            Cache::forget($this->heartbeatKey($participant));
        }
    }

    /**
     * @return array{
     *     last_seen_at: ?string,
     *     heartbeat_interval_seconds: int,
     *     timeout_seconds: int,
     *     is_alive: bool,
     *     is_stale: bool
     * }
     */
    public function snapshot(ConferenceRoomParticipant $participant): array
    {
        $heartbeat = Cache::get($this->heartbeatKey($participant));
        $lastSeenAt = is_array($heartbeat) && is_string($heartbeat['last_seen_at'] ?? null)
            ? Carbon::parse($heartbeat['last_seen_at'])->utc()
            : null;

        $isStale = $lastSeenAt === null
            ? true
            : $lastSeenAt->lt(now()->utc()->subSeconds($this->timeoutSeconds()));

        return [
            'last_seen_at' => $lastSeenAt?->toIso8601ZuluString(),
            'heartbeat_interval_seconds' => $this->heartbeatIntervalSeconds(),
            'timeout_seconds' => $this->timeoutSeconds(),
            'is_alive' => $lastSeenAt !== null && ! $isStale,
            'is_stale' => $isStale,
        ];
    }

    public function isStale(ConferenceRoomParticipant $participant): bool
    {
        return $this->snapshot($participant)['is_stale'];
    }

    public function reconcileRoom(ConferenceRoom $conferenceRoom): int
    {
        $updatedCount = 0;
        $missesBeforeDisconnect = max(1, (int) config('conference.presence.missed_reconciliations_before_disconnect', 2));

        $participants = $conferenceRoom->participants()
            ->with('user')
            ->where('status', ConferenceParticipantStatus::Joined->value)
            ->get();

        foreach ($participants as $participant) {
            if (! $this->isStale($participant)) {
                if ($this->reconcileMissCount($participant) > 0) {
                    $this->updateConferenceRoomParticipantPresence->execute(
                        $participant,
                        ConferenceParticipantStatus::Joined,
                        null,
                        ['heartbeat_reconcile' => ['miss_count' => 0]],
                    );
                }

                continue;
            }

            $nextMissCount = $this->reconcileMissCount($participant) + 1;

            if ($nextMissCount < $missesBeforeDisconnect) {
                $this->updateConferenceRoomParticipantPresence->execute(
                    $participant,
                    ConferenceParticipantStatus::Joined,
                    null,
                    ['heartbeat_reconcile' => ['miss_count' => $nextMissCount]],
                );

                continue;
            }

            $this->markDisconnected($participant, 'heartbeat_timeout');
            $updatedCount++;
        }

        return $updatedCount;
    }

    private function reconcileMissCount(ConferenceRoomParticipant $participant): int
    {
        $metadata = is_array($participant->metadata) ? $participant->metadata : [];

        return max(0, (int) data_get($metadata, 'heartbeat_reconcile.miss_count', 0));
    }

    public function markLeft(ConferenceRoomParticipant $participant, string $reason): ConferenceRoomParticipant
    {
        $updatedParticipant = $this->updateConferenceRoomParticipantPresence->execute(
            $participant,
            ConferenceParticipantStatus::Left,
            now(),
            [
                'presence' => [
                    'last_seen_at' => $this->snapshot($participant)['last_seen_at'],
                    'transition_reason' => $reason,
                    'left_at' => now()->toIso8601String(),
                ],
            ],
        );

        $this->clearHeartbeat($updatedParticipant);

        return $updatedParticipant;
    }

    public function markDisconnected(ConferenceRoomParticipant $participant, string $reason): ConferenceRoomParticipant
    {
        $snapshot = $this->snapshot($participant);

        $updatedParticipant = $this->updateConferenceRoomParticipantPresence->execute(
            $participant,
            ConferenceParticipantStatus::Disconnected,
            now(),
            [
                'presence' => [
                    'last_seen_at' => $snapshot['last_seen_at'],
                    'transition_reason' => $reason,
                    'disconnected_at' => now()->toIso8601String(),
                ],
            ],
        );

        $this->clearHeartbeat($updatedParticipant);

        return $updatedParticipant;
    }

    private function heartbeatKey(ConferenceRoomParticipant $participant): string
    {
        return sprintf(
            'conference_rooms:%s:participants:%s:heartbeat',
            $participant->conference_room_id,
            $participant->public_id,
        );
    }

    private function heartbeatIntervalSeconds(): int
    {
        return max(1, (int) config('conference.presence.heartbeat_interval_seconds', 15));
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('conference.presence.timeout_seconds', 40));
    }

    private function cacheTtlSeconds(): int
    {
        return $this->timeoutSeconds() + $this->heartbeatIntervalSeconds();
    }
}
