<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'subject' => $this->subject,
            'status' => $this->status,
            'priority' => $this->priority,
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'call_log_id' => $this->whenLoaded('callLog', fn () => $this->callLog?->public_id),
            'lead_id' => $this->whenLoaded('lead', fn () => $this->lead?->public_id),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee !== null ? [
                'id' => $this->assignee->public_id,
                'name' => $this->assignee->name,
            ] : null),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator !== null ? [
                'id' => $this->creator->public_id,
                'name' => $this->creator->name,
            ] : null),
            'messages_count' => $this->whenCounted('messages'),
            'messages' => TicketMessageResource::collection($this->whenLoaded('messages')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
