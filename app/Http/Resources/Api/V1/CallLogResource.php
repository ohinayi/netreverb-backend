<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CallLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'caller_number' => $this->caller_number,
            'callee_number' => $this->callee_number,
            'status' => $this->status,
            'duration' => $this->duration,
            'recording' => $this->recording_url ? [
                'url' => $this->recording_url,
                'duration' => $this->recording_duration,
                'size' => $this->recording_size,
            ] : null,
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'caller_extension' => ExtensionResource::make($this->whenLoaded('callerExtension')),
            'callee_extension' => ExtensionResource::make($this->whenLoaded('calleeExtension')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
