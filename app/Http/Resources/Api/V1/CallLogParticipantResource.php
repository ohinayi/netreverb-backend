<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CallLogParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'extension' => ExtensionResource::make($this->whenLoaded('extension')),
            'joined_at' => $this->joined_at,
            'left_at' => $this->left_at,
        ];
    }
}
