<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'account_type' => $this->account_type?->value ?? $this->account_type,
            'email_verified' => $this->hasVerifiedEmail(),
            'country_code' => $this->country_code,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'extensions' => ExtensionResource::collection($this->whenLoaded('extensions')),
            'created_at' => $this->created_at,
        ];
    }
}
