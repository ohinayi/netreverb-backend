<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'lead_id', 'recorded_by_user_id', 'channel', 'destination',
    'consent_status', 'consent_source', 'consented_at', 'suppressed_at',
    'suppression_reason',
])]
class LeadContactChannel extends Model
{
    use HasUlids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return ['consented_at' => 'datetime', 'suppressed_at' => 'datetime'];
    }
}
