<?php

namespace App\Models;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use Database\Factories\OrganizationMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'workspace_id', 'user_id', 'department_id', 'auto_assign_extension', 'invited_by', 'role', 'status', 'joined_at'])]
class OrganizationMembership extends Model
{
    /** @use HasFactory<OrganizationMembershipFactory> */
    use BelongsToWorkspace, HasFactory, HasUlids;

    protected $attributes = [
        'role' => MembershipRole::Agent->value,
        'status' => MembershipStatus::Active->value,
        'auto_assign_extension' => false,
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

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
            'auto_assign_extension' => 'boolean',
        ];
    }
}
