<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\ExtensionStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FriendshipResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'status' => $this->status?->value ?? $this->status,
            'requested_at' => $this->requested_at,
            'responded_at' => $this->responded_at,
            'note' => $this->note,
            'requester' => $this->whenLoaded('requester', fn (): array => $this->personSummary($this->requester)),
            'addressee' => $this->whenLoaded('addressee', fn (): array => $this->personSummary($this->addressee)),
        ];
    }

    /** @return array<string, mixed> */
    private function personSummary(User $user): array
    {
        return [
            'public_id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            // Only a callable extension is useful to a caller adding this
            // friend to a call - an inactive one would just fail to ring.
            'extensions' => $user->relationLoaded('extensions')
                ? ExtensionResource::collection($user->extensions->where('status', ExtensionStatus::Active))
                : [],
        ];
    }
}
