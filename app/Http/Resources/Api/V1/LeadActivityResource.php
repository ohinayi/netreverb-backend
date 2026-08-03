<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'type' => $this->type,
            'summary' => $this->summary,
            'metadata' => $this->metadata,
            'actor' => $this->whenLoaded('actor', fn (): ?array => $this->actor === null ? null : [
                'id' => $this->actor->public_id,
                'name' => $this->actor->name,
            ]),
            'call' => $this->whenLoaded('callLog', fn (): ?array => $this->callLog === null ? null : [
                'id' => $this->callLog->public_id,
                'caller_number' => $this->callLog->caller_number,
                'callee_number' => $this->callLog->callee_number,
                'status' => $this->callLog->status instanceof \BackedEnum
                    ? $this->callLog->status->value
                    : $this->callLog->status,
                'duration' => $this->callLog->duration,
                'started_at' => $this->callLog->started_at,
                'ended_at' => $this->callLog->ended_at,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
