<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationParticipantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'role' => $this->role,
            'joined_at' => $this->joined_at,
            'left_at' => $this->left_at,
            'muted_at' => $this->muted_at,
            'user' => $this->whenLoaded('user', fn (): ?array => [
                'public_id' => $this->user->public_id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
        ];
    }
}
