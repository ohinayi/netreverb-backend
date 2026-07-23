<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConferenceRoomParticipantPresenceUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array{
     *     conference_room_public_id: string,
     *     participant_public_id: string,
     *     display_name: string,
     *     status: string,
     *     last_seen_at: ?string,
     *     left_at: ?string,
     *     disconnected_at: ?string,
     *     reason: ?string
     * }  $payload
     */
    public function __construct(public array $payload) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conference.rooms.'.$this->payload['conference_room_public_id']),
        ];
    }

    public function broadcastAs(): string
    {
        return match ($this->payload['status']) {
            'joined' => 'conference.participant.joined',
            'disconnected' => 'conference.participant.disconnected',
            default => 'conference.participant.left',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->broadcastAs(),
            'data' => $this->payload,
        ];
    }
}
