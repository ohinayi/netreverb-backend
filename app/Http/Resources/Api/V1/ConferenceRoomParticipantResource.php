<?php

namespace App\Http\Resources\Api\V1;

use App\Services\ConferenceRooms\ConferenceRoomParticipantPresenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConferenceRoomParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $screenShare = is_array($this->metadata['screen_share'] ?? null)
            ? $this->metadata['screen_share']
            : [];

        return [
            'public_id' => $this->public_id,
            'display_name' => $this->display_name,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status?->value ?? $this->status,
            'presence' => app(ConferenceRoomParticipantPresenceService::class)->snapshot($this->resource),
            'moderation' => [
                'audio_muted_by_host' => (bool) data_get($this->metadata, 'moderation.audio_muted_by_host', false),
                'video_muted_by_host' => (bool) data_get($this->metadata, 'moderation.video_muted_by_host', false),
                'screen_share_blocked_by_host' => (bool) data_get($screenShare, 'blocked_by_host', false),
            ],
            'screen_share' => [
                'is_sharing' => (bool) data_get($screenShare, 'is_sharing', data_get($screenShare, 'active', false)),
                'active' => (bool) data_get($screenShare, 'active', data_get($screenShare, 'is_sharing', false)),
                'started_at' => data_get($screenShare, 'started_at'),
                'stopped_at' => data_get($screenShare, 'stopped_at'),
                'blocked_by_host' => (bool) data_get($screenShare, 'blocked_by_host', false),
                'blocked_at' => data_get($screenShare, 'blocked_at'),
            ],
            'hand_raised' => (bool) data_get($this->metadata, 'reactions.hand.raised', false),
            'hand_raised_at' => data_get($this->metadata, 'reactions.hand.raised_at'),
            'invited_at' => $this->invited_at,
            'joined_at' => $this->joined_at,
            'left_at' => $this->left_at,
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'public_id' => $this->user->public_id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
        ];
    }
}
