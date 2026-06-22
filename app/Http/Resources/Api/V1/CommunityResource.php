<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'owner' => $this->whenLoaded('owner', fn (): ?array => [
                'public_id' => $this->owner->public_id,
                'name' => $this->owner->name,
                'email' => $this->owner->email,
            ]),
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'visibility' => $this->visibility?->value ?? $this->visibility,
            'status' => $this->status,
            'settings' => $this->settings,
            'archived_at' => $this->archived_at,
            'member_count' => $this->whenCounted('memberships'),
            'department_count' => $this->whenCounted('departments'),
        ];
    }
}
