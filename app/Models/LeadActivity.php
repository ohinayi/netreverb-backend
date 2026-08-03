<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'workspace_id',
    'lead_id',
    'actor_user_id',
    'call_log_id',
    'type',
    'summary',
    'metadata',
])]
class LeadActivity extends Model
{
    use BelongsToWorkspace, HasUlids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
