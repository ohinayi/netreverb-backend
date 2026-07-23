<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\ConversationKind;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'kind' => $this->kind instanceof ConversationKind ? $this->kind->value : $this->kind,
            'type' => $this->kind instanceof ConversationKind ? $this->kind->value : $this->kind,
            'title' => $this->title,
            'direct_key' => $this->direct_key,
            'last_message_at' => $this->last_message_at,
            'last_message' => new MessageResource($this->whenLoaded('lastMessage')),
            'archived_at' => $this->archived_at,
            'community' => $this->whenLoaded('community', fn (): ?array => $this->community === null ? null : [
                'public_id' => $this->community->public_id,
                'name' => $this->community->name,
                'slug' => $this->community->slug,
            ]),
            'participant_count' => $this->whenCounted('participants'),
            'members' => UserResource::collection(
                $this->whenLoaded('participants', fn () => $this->participants->pluck('user'))
            ),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
