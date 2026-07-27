<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'department_id', 'extension_id', 'strategy', 'agent_ring_timeout_seconds',
    'max_wait_seconds', 'empty_queue_action', 'fallback_extension_id', 'enabled',
])]
class CallQueue extends Model
{
    use HasUlids;

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

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class);
    }

    public function fallbackExtension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'fallback_extension_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CallQueueMember::class)->orderBy('priority')->orderBy('id');
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
