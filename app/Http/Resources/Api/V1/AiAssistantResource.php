<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiAssistantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'extension_id' => $this->when($this->extension !== null, $this->extension?->public_id),
            'enabled' => $this->enabled,
            'language' => $this->language,
            'welcome_message' => $this->welcome_message,
            'system_instruction' => $this->system_instruction,
            'knowledge' => $this->knowledge,
            'handoff_rules' => $this->handoff_rules,
            'fields' => AiAssistantFieldResource::collection($this->whenLoaded('fields')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
