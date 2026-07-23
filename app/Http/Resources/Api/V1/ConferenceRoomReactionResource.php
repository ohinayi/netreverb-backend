<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConferenceRoomReactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'reaction_type' => $this->reaction_type,
            'payload' => $this->payload,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
            'participant' => $this->whenLoaded('participant', fn (): ?array => $this->participant === null ? null : [
                'public_id' => $this->participant->public_id,
                'display_name' => $this->participant->display_name,
                'role' => $this->participant->role,
                'status' => $this->participant->status?->value ?? $this->participant->status,
            ]),
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'public_id' => $this->user->public_id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
        ];
    }
}
