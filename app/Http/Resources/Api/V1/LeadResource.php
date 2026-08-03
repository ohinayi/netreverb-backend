<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'company' => $this->company,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'value' => $this->value,
            'notes' => $this->notes,
            'last_contacted_at' => $this->last_contacted_at,
            'follow_up_at' => $this->follow_up_at,
            'follow_up_notified_at' => $this->follow_up_notified_at,
            'follow_up_completed_at' => $this->follow_up_completed_at,
            'assigned_user' => $this->whenLoaded('assignedUser', fn (): ?array => $this->assignedUser === null ? null : [
                'id' => $this->assignedUser->public_id,
                'name' => $this->assignedUser->name,
                'email' => $this->assignedUser->email,
            ]),
            'organization' => $this->whenLoaded('organization', fn (): array => [
                'id' => $this->organization->public_id,
                'name' => $this->organization->name,
                'slug' => $this->organization->slug,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
