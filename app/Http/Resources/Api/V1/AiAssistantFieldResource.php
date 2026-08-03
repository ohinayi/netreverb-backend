<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiAssistantFieldResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'key' => $this->key,
            'label' => $this->label,
            'field_type' => $this->field_type,
            'question' => $this->question,
            'required' => $this->required,
            'options' => $this->options,
            'sort_order' => $this->sort_order,
        ];
    }
}
