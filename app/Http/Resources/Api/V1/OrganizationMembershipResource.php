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
                    ->value('role'), ['owner', 'admin', 'telephony_admin']),
            'role' => $this->role?->value ?? $this->role,
            'status' => $this->status?->value ?? $this->status,
            'auto_assign_extension' => (bool) $this->auto_assign_extension,
            'joined_at' => $this->joined_at,
            'workspace' => $this->whenLoaded('workspace', fn (): ?array => $this->workspace === null ? null : [
                'id' => $this->workspace->public_id,
                'name' => $this->workspace->name,
                'slug' => $this->workspace->slug,
                'kind' => $this->workspace->kind,
            ]),
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'id' => $this->user->public_id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'extensions' => $this->whenLoaded('user', fn (): array => $this->user?->relationLoaded('extensions')
                ? $this->user->extensions->map(fn ($extension): array => [
                    'id' => $extension->public_id,
                    'number' => $extension->dialableNumber->number,
                    'status' => $extension->status?->value ?? $extension->status,
                    'display_name' => $extension->display_name,
                ])->all()
                : []),
            'department' => $this->whenLoaded('department', fn (): ?array => $this->department === null ? null : [
                'id' => $this->department->public_id,
                'name' => $this->department->name,
                'slug' => $this->department->slug,
            ]),
        ];
    }
}
