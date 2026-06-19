<?php

namespace App\Models;

use App\Enums\ProvisioningEventStatus;
use App\Enums\ProvisioningOperation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'extension_id',
    'operation',
    'revision',
    'status',
    'attempts',
    'available_at',
    'processed_at',
    'last_error',
])]
class SipProvisioningEvent extends Model
{
    use HasUlids;

    protected $attributes = [
        'status' => ProvisioningEventStatus::Pending->value,
        'attempts' => 0,
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class);
    }

    protected function casts(): array
    {
        return [
            'operation' => ProvisioningOperation::class,
            'status' => ProvisioningEventStatus::class,
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
