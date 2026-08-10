<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'body' => $this->body,
            'status' => $this->status?->value ?? $this->status,
            'created_at' => $this->created_at,
            'responded_at' => $this->responded_at,
            'conversation_id' => $this->whenLoaded('conversation', fn () => $this->conversation?->public_id, $this->conversation_id),
            'sender' => $this->whenLoaded('sender', fn (): ?array => [
                'public_id' => $this->sender->public_id,
                'name' => $this->sender->name,
                'email' => $this->sender->email,
            ]),
            'recipient' => $this->whenLoaded('recipient', fn (): ?array => [
                'public_id' => $this->recipient->public_id,
                'name' => $this->recipient->name,
                'email' => $this->recipient->email,
            ]),
        ];
    }
}
