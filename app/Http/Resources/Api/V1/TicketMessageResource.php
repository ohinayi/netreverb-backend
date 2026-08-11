<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'type' => $this->type,
            'body' => $this->body,
            'author' => $this->whenLoaded('author', fn () => $this->author !== null ? [
                'id' => $this->author->public_id,
                'name' => $this->author->name,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
