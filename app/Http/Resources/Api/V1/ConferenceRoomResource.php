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
            'starts_at' => $this->loadedAttribute('starts_at'),
            'expires_at' => $this->loadedAttribute('expires_at'),
            'ended_at' => $this->loadedAttribute('ended_at'),
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
            'reactions' => ConferenceRoomReactionResource::collection($this->whenLoaded('reactions')),
            'presence' => [
                'heartbeat_interval_seconds' => (int) config('conference.presence.heartbeat_interval_seconds', 15),
                'timeout_seconds' => (int) config('conference.presence.timeout_seconds', 40),
                'heartbeat_url' => route('organizations.conference-rooms.presence.heartbeat', [$this->organization, $this]),
                'disconnect_url' => route('organizations.conference-rooms.presence.disconnect', [$this->organization, $this]),
            ],
            'chat' => [
                'channel_name' => 'private-conference.chat.'.$this->public_id,
                'websocket_url' => str_replace('{conferenceRoom}', $this->public_id, (string) config('conference.chat.websocket_url')),
                'stream_url' => route('conference-rooms.chat.stream', $this),
                'messages_url' => route('conference-rooms.chat.messages.store', $this),
                'bootstrap_url' => route('conference-rooms.chat.show', $this),
            ],
            'current_user_participant' => $this->whenLoaded(
                'currentUserParticipant',
                fn (): ?ConferenceRoomParticipantResource => $this->currentUserParticipant === null
                    ? null
                    : ConferenceRoomParticipantResource::make($this->currentUserParticipant),
            ),
            'passcode_required' => $this->passcode_hash !== null,
        ];
    }

    private function loadedAttribute(string $attribute): mixed
    {
        return array_key_exists($attribute, $this->resource->getAttributes())
            ? $this->resource->getAttribute($attribute)
            : null;
    }
}
