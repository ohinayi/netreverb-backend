<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id', 'workspace_id', 'message_template_id', 'created_by_user_id', 'name',
    'channel', 'status', 'timezone', 'quiet_hours_start', 'quiet_hours_end',
    'rate_limit_per_minute', 'scheduled_at', 'started_at', 'completed_at',
])]
class OutboundCampaign extends Model
{
    use BelongsToWorkspace, HasUlids, SoftDeletes;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(OutboundCampaignRecipient::class);
    }

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
