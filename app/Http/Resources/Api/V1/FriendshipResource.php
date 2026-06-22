<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FriendshipResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'status' => $this->status?->value ?? $this->status,
            'requested_at' => $this->requested_at,
            'responded_at' => $this->responded_at,
            'note' => $this->note,
            'requester' => $this->whenLoaded('requester', fn (): ?array => [
                'public_id' => $this->requester->public_id,
                'name' => $this->requester->name,
                'email' => $this->requester->email,
            ]),
            'addressee' => $this->whenLoaded('addressee', fn (): ?array => [
                'public_id' => $this->addressee->public_id,
                'name' => $this->addressee->name,
                'email' => $this->addressee->email,
            ]),
        ];
    }
}
