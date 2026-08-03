<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceNumber;
use App\Models\User;

class ServiceNumberPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->activeMembership($user, $organization) !== null;
    }

    public function view(User $user, ServiceNumber $serviceNumber): bool
    {
        return $serviceNumber->organization_id !== null
            && $this->activeMembership($user, $serviceNumber->organization_id) !== null;
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->canManage($user, $organization);
    }

    public function update(User $user, ServiceNumber $serviceNumber): bool
    {
        return $serviceNumber->organization_id !== null
            && $this->canManage($user, $serviceNumber->organization_id);
    }

    public function delete(User $user, ServiceNumber $serviceNumber): bool
    {
        return $serviceNumber->organization_id !== null
            && $this->canManage($user, $serviceNumber->organization_id);
    }

    public function restore(User $user, ServiceNumber $serviceNumber): bool
    {
        return false;
    }

    public function forceDelete(User $user, ServiceNumber $serviceNumber): bool
    {
        return false;
    }

    private function canManage(User $user, Organization|int $organization): bool
    {
        if ($user->isSuperAdmin()) return true;

        return in_array(
            $this->activeMembership($user, $organization)?->role,
            [MembershipRole::Owner, MembershipRole::Admin, MembershipRole::TelephonyAdmin],
            strict: true,
        );
    }

    private function activeMembership(
        User $user,
        Organization|int $organization,
    ): ?OrganizationMembership {
        return OrganizationMembership::query()
            ->where('organization_id', $organization instanceof Organization ? $organization->id : $organization)
            ->whereBelongsTo($user)
            ->where('status', MembershipStatus::Active->value)
            ->first();
    }
}
