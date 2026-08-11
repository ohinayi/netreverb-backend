<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CallNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'body' => $this->body,
            'author' => $this->whenLoaded('user', fn () => $this->user !== null ? [
                'id' => $this->user->public_id,
                'name' => $this->user->name,
            ] : null),
            'call_log' => $this->whenLoaded('callLog', fn () => $this->callLog !== null ? [
                'id' => $this->callLog->public_id,
                'caller_number' => $this->callLog->caller_number,
                'callee_number' => $this->callLog->callee_number,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
