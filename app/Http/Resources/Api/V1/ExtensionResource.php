<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExtensionResource extends JsonResource
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
            'number' => $this->dialableNumber->number,
            'realm' => $this->dialableNumber->realm,
            'sip_uri' => 'sip:'.$this->dialableNumber->number.'@'.$this->dialableNumber->realm,
            'display_name' => $this->display_name,
            'type' => $this->type,
            'status' => $this->status,
            'user_id' => $this->user?->public_id,
            'unavailable_action' => $this->unavailable_action,
            'fallback_extension_id' => $this->fallbackExtension?->public_id,
            'ring_timeout_seconds' => $this->ring_timeout_seconds,
            'provisioning' => $this->whenLoaded('provisioningState', fn (): array => [
                'status' => $this->provisioningState->status,
                'desired_revision' => $this->provisioningState->desired_revision,
                'applied_revision' => $this->provisioningState->applied_revision,
                'provisioned_at' => $this->provisioningState->provisioned_at,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
