<?php

namespace App\Models;

use App\Enums\ProvisioningStatus;
use App\Enums\ServiceNumberType;
use Database\Factories\ServiceNumberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id',
    'dialable_number_id',
    'name',
    'type',
    'target',
    'enabled',
    'provisioning_status',
    'configuration',
])]
class ServiceNumber extends Model
{
    /** @use HasFactory<ServiceNumberFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'enabled' => true,
        'provisioning_status' => ProvisioningStatus::Pending->value,
    ];

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

    public function dialableNumber(): BelongsTo
    {
        return $this->belongsTo(DialableNumber::class);
    }

    protected function casts(): array
    {
        return [
            'type' => ServiceNumberType::class,
            'enabled' => 'boolean',
            'provisioning_status' => ProvisioningStatus::class,
            'configuration' => 'array',
        ];
    }
}
