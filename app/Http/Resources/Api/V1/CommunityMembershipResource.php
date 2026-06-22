<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunityMembershipResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'role' => $this->role?->value ?? $this->role,
            'status' => $this->status?->value ?? $this->status,
            'joined_at' => $this->joined_at,
            'left_at' => $this->left_at,
            'user' => $this->whenLoaded('user', fn (): ?array => [
                'public_id' => $this->user->public_id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'department' => $this->whenLoaded('communityDepartment', fn (): ?array => $this->communityDepartment === null ? null : [
                'public_id' => $this->communityDepartment->public_id,
                'name' => $this->communityDepartment->name,
                'slug' => $this->communityDepartment->slug,
            ]),
        ];
    }
}
