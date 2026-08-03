<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'requested_by_user_id', 'completed_by_user_id', 'reference',
    'payment_reference', 'payment_method', 'currency', 'amount_minor', 'units',
    'cost_per_unit_minor', 'selling_per_unit_minor', 'profit_minor', 'status',
    'completed_at', 'metadata',
])]
class SmsCreditPurchase extends Model
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'units' => 'integer',
            'cost_per_unit_minor' => 'integer',
            'selling_per_unit_minor' => 'integer',
            'profit_minor' => 'integer',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
