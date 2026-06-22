<?php

namespace App\Models;

use App\Enums\ExtensionProvisioningMode;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name',
    'slug',
    'status',
    'extension_provisioning_mode',
    'timezone',
    'locale',
    'settings',
])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'status' => OrganizationStatus::Active->value,
        'extension_provisioning_mode' => ExtensionProvisioningMode::Manual->value,
        'timezone' => 'UTC',
        'locale' => 'en',
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_memberships')
            ->withPivot(['public_id', 'role', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class);
    }

    public function serviceNumbers(): HasMany
    {
        return $this->hasMany(ServiceNumber::class);
    }

    public function conferenceRooms(): HasMany
    {
        return $this->hasMany(ConferenceRoom::class);
    }

    public function dialableNumbers(): HasMany
    {
        return $this->hasMany(DialableNumber::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'memberships',
            fn (Builder $membershipQuery): Builder => $membershipQuery
                ->whereBelongsTo($user)
                ->where('status', MembershipStatus::Active),
        );
    }

    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'extension_provisioning_mode' => ExtensionProvisioningMode::class,
            'settings' => 'array',
        ];
    }
}
