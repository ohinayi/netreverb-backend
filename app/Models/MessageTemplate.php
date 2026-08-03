<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id', 'workspace_id', 'created_by_user_id', 'reviewed_by_user_id', 'name',
    'channel', 'body', 'status', 'review_note', 'reviewed_at',
])]
class MessageTemplate extends Model
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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }
}
