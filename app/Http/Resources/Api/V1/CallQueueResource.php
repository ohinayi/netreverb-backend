<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CallQueueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'extension_id' => $this->whenLoaded('extension', fn (): ?string => $this->extension?->public_id),
            'number' => $this->whenLoaded('extension', fn (): ?string => $this->extension?->dialableNumber?->number),
            'display_name' => $this->whenLoaded('extension', fn (): ?string => $this->extension?->display_name),
            'strategy' => $this->strategy,
            'agent_ring_timeout_seconds' => $this->agent_ring_timeout_seconds,
            'max_wait_seconds' => $this->max_wait_seconds,
            'empty_queue_action' => $this->empty_queue_action,
            'fallback_extension_id' => $this->whenLoaded('fallbackExtension', fn (): ?string => $this->fallbackExtension?->public_id),
            'enabled' => $this->enabled,
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($member) => [
                'extension_id' => $member->extension?->public_id,
                'number' => $member->extension?->dialableNumber?->number,
                'display_name' => $member->extension?->display_name,
                'priority' => $member->priority,
                'enabled' => $member->enabled,
            ])->values()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
