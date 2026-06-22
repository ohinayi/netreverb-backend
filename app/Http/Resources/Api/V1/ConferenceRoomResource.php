<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConferenceRoomResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'room_id' => $this->room_id,
            'sip_number' => $this->sip_number,
            'title' => $this->title,
            'status' => $this->status?->value ?? $this->status,
            'expires_at' => $this->expires_at,
            'ended_at' => $this->ended_at,
            'created_at' => $this->created_at,
            'host' => $this->whenLoaded('hostUser', fn (): ?array => $this->hostUser === null ? null : [
                'public_id' => $this->hostUser->public_id,
                'name' => $this->hostUser->name,
                'email' => $this->hostUser->email,
            ]),
            'ended_by' => $this->whenLoaded('endedByUser', fn (): ?array => $this->endedByUser === null ? null : [
                'public_id' => $this->endedByUser->public_id,
                'name' => $this->endedByUser->name,
                'email' => $this->endedByUser->email,
            ]),
            'participant_count' => $this->whenCounted('participants'),
            'participants' => ConferenceRoomParticipantResource::collection($this->whenLoaded('participants')),
            'passcode_required' => $this->passcode_hash !== null,
        ];
    }
}
