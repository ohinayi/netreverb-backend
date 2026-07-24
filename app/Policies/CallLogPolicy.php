<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class CallLogPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->activeMembership($user, $organization) !== null;
    }

    public function viewAll(User $user, Organization $organization): bool
    {
        return $this->canViewAll($user, $organization);
    }

    public function view(User $user, CallLog $callLog): bool
    {
        if ($this->activeMembership($user, $callLog->organization_id) === null) {
            return false;
        }

        if ($this->canViewAll($user, $callLog->organization_id)) {
            return true;
        }

        $userExtensionIds = $user->extensions()->pluck('id')->toArray();

        return ($callLog->caller_extension_id !== null && in_array($callLog->caller_extension_id, $userExtensionIds, true))
            || ($callLog->callee_extension_id !== null && in_array($callLog->callee_extension_id, $userExtensionIds, true));
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->activeMembership($user, $organization) !== null;
    }

    public function update(User $user, CallLog $callLog): bool
    {
        if ($this->activeMembership($user, $callLog->organization_id) === null) {
            return false;
        }

        if ($this->canManage($user, $callLog->organization_id)) {
            return true;
        }

        $userExtensionIds = $user->extensions()->pluck('id')->toArray();

        return ($callLog->caller_extension_id !== null && in_array($callLog->caller_extension_id, $userExtensionIds, true))
            || ($callLog->callee_extension_id !== null && in_array($callLog->callee_extension_id, $userExtensionIds, true));
    }

    public function delete(User $user, CallLog $callLog): bool
    {
        return $this->canManage($user, $callLog->organization_id);
    }

    public function transfer(User $user, CallLog $callLog): bool
    {
        return $this->canManage($user, $callLog->organization_id);
    }

    public function restore(User $user, CallLog $callLog): bool
    {
        return false;
    }

    public function forceDelete(User $user, CallLog $callLog): bool
    {
        return false;
    }

    private function canManage(User $user, Organization|int $organization): bool
    {
        if ($user->isSuperAdmin()) return true;

        return in_array(
            $this->activeMembership($user, $organization)?->role,
            [
                MembershipRole::Owner,
                MembershipRole::Admin,
                MembershipRole::TelephonyAdmin,
                MembershipRole::Supervisor,
            ],
            strict: true,
        );
    }

    private function canViewAll(User $user, Organization|int $organization): bool
    {
        if ($user->isSuperAdmin()) return true;

        return in_array(
            $this->activeMembership($user, $organization)?->role,
            [
                MembershipRole::Owner,
                MembershipRole::Admin,
                MembershipRole::TelephonyAdmin,
                MembershipRole::Supervisor,
                MembershipRole::Auditor,
            ],
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
