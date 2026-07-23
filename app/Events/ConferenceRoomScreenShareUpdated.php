<?php

namespace App\Events;

use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConferenceRoomScreenShareUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly ConferenceRoom $conferenceRoom,
        public readonly ConferenceRoomParticipant $participant,
        public readonly string $action,
        public readonly string $source,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conference.rooms.'.$this->conferenceRoom->public_id),
        ];
    }

    public function broadcastAs(): string
    {
        return match ($this->action) {
            'started' => 'conference.screen_share.started',
            'stopped' => 'conference.screen_share.stopped',
            'blocked' => 'conference.screen_share.blocked',
            'unblocked' => 'conference.screen_share.unblocked',
            default => 'conference.screen_share.updated',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $screenShare = is_array($this->participant->metadata['screen_share'] ?? null)
            ? $this->participant->metadata['screen_share']
            : [];

        $mediaTrackKey = implode(':', [
            $this->conferenceRoom->public_id,
            $this->participant->public_id,
            $this->source,
        ]);

        return [
            'type' => $this->broadcastAs(),
            'conference_room_public_id' => $this->conferenceRoom->public_id,
            'participant_public_id' => $this->participant->public_id,
            'display_name' => $this->participant->display_name,
            'source' => $this->source,
            'action' => $this->action,
            'media_track_key' => $mediaTrackKey,
            'is_sharing' => (bool) data_get($screenShare, 'is_sharing', data_get($screenShare, 'active', false)),
            'blocked_by_host' => (bool) data_get($screenShare, 'blocked_by_host', false),
            'screen_share' => [
                'is_sharing' => (bool) data_get($screenShare, 'is_sharing', data_get($screenShare, 'active', false)),
                'blocked_by_host' => (bool) data_get($screenShare, 'blocked_by_host', false),
                'started_at' => data_get($screenShare, 'started_at'),
                'stopped_at' => data_get($screenShare, 'stopped_at'),
                'blocked_at' => data_get($screenShare, 'blocked_at'),
            ],
            'participant' => [
                'public_id' => $this->participant->public_id,
                'display_name' => $this->participant->display_name,
                'email' => $this->participant->email,
                'role' => $this->participant->role,
                'status' => $this->participant->status?->value ?? $this->participant->status,
                'screen_share' => [
                    'is_sharing' => (bool) data_get($screenShare, 'is_sharing', data_get($screenShare, 'active', false)),
                    'blocked_by_host' => (bool) data_get($screenShare, 'blocked_by_host', false),
                    'started_at' => data_get($screenShare, 'started_at'),
                    'stopped_at' => data_get($screenShare, 'stopped_at'),
                    'blocked_at' => data_get($screenShare, 'blocked_at'),
                ],
            ],
        ];
    }
}
