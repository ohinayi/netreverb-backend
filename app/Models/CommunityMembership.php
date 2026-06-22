<?php

namespace App\Models;

use App\Enums\CommunityMembershipRole;
use App\Enums\CommunityMembershipStatus;
use Database\Factories\CommunityMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'community_id',
    'user_id',
    'community_department_id',
    'invited_by_user_id',
    'role',
    'status',
    'joined_at',
    'left_at',
])]
class CommunityMembership extends Model
{
    /** @use HasFactory<CommunityMembershipFactory> */
    use HasFactory, HasUlids;

    protected $attributes = [
        'role' => CommunityMembershipRole::Member->value,
        'status' => CommunityMembershipStatus::Active->value,
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function communityDepartment(): BelongsTo
    {
        return $this->belongsTo(CommunityDepartment::class);
    }

    public function invitedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'role' => CommunityMembershipRole::class,
            'status' => CommunityMembershipStatus::class,
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }
}
