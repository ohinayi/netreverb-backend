<?php

namespace App\Models;

use App\Enums\CommunityVisibility;
use Database\Factories\CommunityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'owner_user_id',
    'name',
    'slug',
    'description',
    'visibility',
    'status',
    'settings',
    'archived_at',
])]
class Community extends Model
{
    /** @use HasFactory<CommunityFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'visibility' => CommunityVisibility::Private->value,
        'status' => 'active',
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(CommunityDepartment::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CommunityMembership::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    protected function casts(): array
    {
        return [
            'visibility' => CommunityVisibility::class,
            'settings' => 'array',
            'archived_at' => 'datetime',
        ];
    }
}
