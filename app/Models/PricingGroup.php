<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name', 'slug', 'description', 'applies_to', 'price_minor', 'currency',
    'billing_interval', 'features', 'is_active',
])]
class PricingGroup extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'applies_to' => 'organization',
        'price_minor' => 0,
        'currency' => 'USD',
        'billing_interval' => 'monthly',
        'is_active' => true,
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    /** Whether this group's plan includes the given module key. */
    public function hasFeature(string $key): bool
    {
        return in_array($key, $this->features ?? [], true);
    }

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
