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
            'organization_public_id' => $this->whenLoaded('organization', fn (): string => $this->organization->public_id),
            'invite_code' => $this->whenHas('invite_code'),
            'invite_url' => $this->whenHas(
                'invite_code',
                fn (): string => rtrim((string) config('app.frontend_url'), '/').'/app/meetings/join?invite='.$this->invite_code,
            ),
            'is_open' => $this->isOpen(),
            'sip_number' => $this->whenHas('join_sip_number'),
            'can_join_directly' => $this->whenHas('can_join_directly'),
            'waiting_room_required' => $this->whenHas('waiting_room_required'),
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
            'current_user_participant' => $this->whenLoaded(
                'currentUserParticipant',
                fn (): ?ConferenceRoomParticipantResource => $this->currentUserParticipant === null
                    ? null
                    : ConferenceRoomParticipantResource::make($this->currentUserParticipant),
            ),
            'passcode_required' => $this->passcode_hash !== null,
        ];
    }
}
