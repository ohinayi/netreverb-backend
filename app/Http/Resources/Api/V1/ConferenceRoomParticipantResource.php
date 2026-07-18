<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConferenceRoomParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'display_name' => $this->display_name,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status?->value ?? $this->status,
            'moderation' => [
                'audio_muted_by_host' => (bool) data_get($this->metadata, 'moderation.audio_muted_by_host', false),
                'video_muted_by_host' => (bool) data_get($this->metadata, 'moderation.video_muted_by_host', false),
            ],
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
