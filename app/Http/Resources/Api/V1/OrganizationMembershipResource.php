<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationMembershipResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'can_manage' => $request->user()?->isSuperAdmin()
                || in_array($request->user()?->organizationMemberships()
                    ->where('organization_id', $this->organization_id)
                    ->value('role'), ['owner', 'admin', 'department_manager'], true),
            'role' => $this->role?->value ?? $this->role,
            'status' => $this->status?->value ?? $this->status,
            'joined_at' => $this->joined_at,
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'id' => $this->user->public_id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'department' => $this->whenLoaded('department', fn (): ?array => $this->department === null ? null : [
                'id' => $this->department->public_id,
                'name' => $this->department->name,
                'slug' => $this->department->slug,
            ]),
        ];
    }
}
