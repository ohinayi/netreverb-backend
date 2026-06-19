<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceNumberResource extends JsonResource
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
            'name' => $this->name,
            'type' => $this->type,
            'target' => $this->target,
            'enabled' => $this->enabled,
            'provisioning_status' => $this->provisioning_status,
            'configuration' => $this->configuration,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
