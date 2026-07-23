<?php

namespace App\Http\Resources\Api\V1;

use App\Services\ConferenceRooms\ConferenceRoomParticipantPresenceService;
use App\Services\Telephony\ConferenceLiveMemberResolver;
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
            'screen_share_leg' => $this->whenLoaded(
                'screenShareParticipant',
                fn (): ?array => $this->screenShareParticipant === null ? null : [
                    'public_id' => $this->screenShareParticipant->public_id,
                    'display_name' => $this->screenShareParticipant->display_name,
                    'sip_number' => $this->conferenceRoom?->sip_number,
                    'caller_name_suffix' => ConferenceLiveMemberResolver::SCREEN_SHARE_CALLER_NAME_SUFFIX,
                ],
            ),
            'hand_raised' => (bool) data_get($this->metadata, 'reactions.hand.raised', false),
            'hand_raised_at' => data_get($this->metadata, 'reactions.hand.raised_at'),
            // Self-reported camera state (distinct from moderation.video_muted_by_host,
            // which is host-imposed). Defaults to true for participants who haven't
            // reported a state yet, so older/never-updated sessions still render live
            // video rather than being assumed camera-off.
            'camera_enabled' => (bool) data_get($this->metadata, 'camera.enabled', true),
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
