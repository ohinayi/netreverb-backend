<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'idempotency_key', 'organization_id', 'workspace_id', 'lead_id', 'message_template_id', 'created_by_user_id',
    'approved_by_user_id', 'channel', 'destination', 'body', 'sms_units',
    'billing_status', 'status',
    'blocked_reason', 'consent_snapshot', 'provider', 'provider_message_id',
    'approved_at', 'sent_at', 'delivered_at', 'failed_at', 'failure_reason',
])]
class OutboundMessage extends Model
{
    use BelongsToWorkspace, HasUlids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    protected function casts(): array
    {
        return [
            'consent_snapshot' => 'array',
            'sms_units' => 'integer',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
