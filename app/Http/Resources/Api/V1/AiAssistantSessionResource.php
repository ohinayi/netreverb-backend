<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiAssistantSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'transcript' => $this->transcript,
            'captured_data' => $this->captured_data,
            'duration_seconds' => $this->duration_seconds,
            'call_log_id' => $this->whenLoaded('callLog', fn (): ?string => $this->callLog?->public_id),
            'caller_number' => $this->whenLoaded('callLog', fn (): ?string => $this->callLog?->caller_number),
            'created_at' => $this->created_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
