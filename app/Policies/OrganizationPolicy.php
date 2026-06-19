<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->activeMembership($user, $organization) !== null;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->canManage($user, $organization);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return false;
    }

    public function restore(User $user, Organization $organization): bool
    {
        return false;
    }

    public function forceDelete(User $user, Organization $organization): bool
    {
        return false;
    }

    private function canManage(User $user, Organization $organization): bool
    {
        return in_array(
            $this->activeMembership($user, $organization)?->role,
            [MembershipRole::Owner, MembershipRole::Admin],
            strict: true,
        );
    }

    private function activeMembership(User $user, Organization $organization): ?OrganizationMembership
    {
        return OrganizationMembership::query()
            ->whereBelongsTo($organization)
            ->whereBelongsTo($user)
            ->where('status', MembershipStatus::Active->value)
            ->first();
    }
}
