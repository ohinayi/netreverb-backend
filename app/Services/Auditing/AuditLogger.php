<?php

namespace App\Services\Auditing;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        Request $request,
        ?User $actor,
        ?Organization $organization,
        string $action,
        ?Model $target = null,
        ?array $before = null,
        ?array $after = null,
    ): AuditEvent {
        return AuditEvent::query()->create([
            'organization_id' => $organization?->id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'target_type' => $target?->getMorphClass(),
            'target_id' => $target?->getKey(),
            'target_public_id' => $target?->getAttribute('public_id'),
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'request_id' => $request->header('X-Request-Id'),
        ]);
    }
}
