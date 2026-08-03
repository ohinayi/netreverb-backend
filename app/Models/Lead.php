<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id', 'workspace_id',
    'assigned_user_id',
    'created_by_user_id',
    'name',
    'company',
    'email',
    'phone',
    'status',
    'value',
    'notes',
    'last_contacted_at',
    'follow_up_at',
    'follow_up_notified_at',
    'follow_up_completed_at',
])]
class Lead extends Model
{
    use BelongsToWorkspace, HasUlids, SoftDeletes;

    protected $attributes = ['status' => 'new'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest('id');
    }

    public function callLogs(): HasManyThrough
    {
        return $this->hasManyThrough(
            CallLog::class,
            LeadActivity::class,
            'lead_id',
            'id',
            'id',
            'call_log_id',
        )->whereNotNull('lead_activities.call_log_id');
    }

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'last_contacted_at' => 'datetime',
            'follow_up_at' => 'datetime',
            'follow_up_notified_at' => 'datetime',
            'follow_up_completed_at' => 'datetime',
        ];
    }
}
