<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'provider', 'currency', 'cost_per_unit_minor', 'selling_per_unit_minor',
    'minimum_purchase_minor', 'updated_by_user_id',
])]
class SmsPricingSetting extends Model
{
    use HasUlids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'cost_per_unit_minor' => 'integer',
            'selling_per_unit_minor' => 'integer',
            'minimum_purchase_minor' => 'integer',
        ];
    }
}
