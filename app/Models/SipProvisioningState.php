<?php

namespace App\Models;

use App\Enums\ProvisioningStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'extension_id',
    'desired_revision',
    'applied_revision',
    'status',
    'last_attempted_at',
    'provisioned_at',
    'last_error',
])]
class SipProvisioningState extends Model
{
    protected $attributes = [
        'desired_revision' => 1,
        'applied_revision' => 0,
        'status' => ProvisioningStatus::Pending->value,
    ];

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ProvisioningStatus::class,
            'last_attempted_at' => 'datetime',
            'provisioned_at' => 'datetime',
        ];
    }
}
