<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $membership = $this->relationLoaded('memberships')
            ? $this->memberships->first()
            : null;

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'settings' => $this->settings,
            'pricing_group_id' => $this->pricingGroup?->public_id,
            'operational_policy' => $this->operationalPolicy(),
            'members_count' => $this->whenCounted('memberships'),
            'departments_count' => $this->whenCounted('departments'),
            'queues_count' => $this->whenCounted('callQueues'),
            'extensions_count' => $this->whenCounted('extensions'),
            'active_extensions_count' => $this->whenCounted('active_extensions'),
            'workspaces_count' => $this->whenCounted('workspaces'),
            'workspaces' => $this->whenLoaded('workspaces', fn () => $this->workspaces->map(fn ($workspace) => [
                'id' => $workspace->public_id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'kind' => $workspace->kind,
                'status' => $workspace->status,
                'members_count' => $workspace->memberships_count ?? $workspace->memberships()->count(),
                'departments_count' => $workspace->departments_count ?? $workspace->departments()->count(),
            ])),
            'membership_role' => $membership?->role?->value ?? $membership?->role,
            'membership_status' => $membership?->status?->value ?? $membership?->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
