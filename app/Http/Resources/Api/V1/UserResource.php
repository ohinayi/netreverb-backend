<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\MembershipStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'account_type' => $this->account_type?->value ?? $this->account_type,
            'has_organization' => $this->organizationMemberships()
                ->where('status', MembershipStatus::Active->value)
                ->exists(),
            'is_super_admin' => $this->isSuperAdmin(),
            'email_verified' => $this->hasVerifiedEmail(),
            'country_code' => $this->country_code,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'extensions' => ExtensionResource::collection($this->whenLoaded('extensions')),
            'created_at' => $this->created_at,
        ];
    }
}
