<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'type' => $this->type?->value ?? $this->type,
            'body' => $this->body,
            'attachment_path' => $this->attachment_path,
            'metadata' => $this->metadata,
            'sent_at' => $this->sent_at,
            'edited_at' => $this->edited_at,
            'sender' => $this->whenLoaded('senderUser', fn (): ?array => $this->senderUser === null ? null : [
                'public_id' => $this->senderUser->public_id,
                'name' => $this->senderUser->name,
                'email' => $this->senderUser->email,
            ]),
        ];
    }
}
