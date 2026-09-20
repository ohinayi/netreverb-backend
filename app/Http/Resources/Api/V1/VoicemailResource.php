<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VoicemailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'extension_id' => $this->whenLoaded('extension', fn (): ?string => $this->extension?->public_id),
            'extension_display_name' => $this->whenLoaded('extension', fn (): ?string => $this->extension?->display_name),
            'extension_number' => $this->whenLoaded('extension', fn (): ?string => $this->extension?->dialableNumber?->number),
            'caller_number' => $this->caller_number,
            'duration_seconds' => $this->duration_seconds,
            'listened_at' => $this->listened_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
