<?php

namespace App\Services\Authorization;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\CallLog;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class CallLogVisibility
{
    public function canViewAll(User $user, Organization|int $organization): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return in_array(
            $this->activeMembership($user, $organization)?->role,
            [MembershipRole::Owner, MembershipRole::Admin, MembershipRole::TelephonyAdmin],
            strict: true,
        );
    }

    /** @return array<int, int> */
    public function accessibleExtensionIds(User $user, Organization|int $organization): array
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;
        $membership = $this->activeMembership($user, $organizationId);

        if ($membership === null) {
            return [];
        }

        if ($membership->role === MembershipRole::Supervisor && $membership->department_id !== null) {
            $departmentUserIds = OrganizationMembership::query()
                ->where('organization_id', $organizationId)
                ->where('department_id', $membership->department_id)
                ->where('status', MembershipStatus::Active->value)
                ->pluck('user_id');

            return Extension::query()
                ->where('organization_id', $organizationId)
                ->whereIn('user_id', $departmentUserIds)
                ->pluck('id')
                ->all();
        }

        return Extension::query()
            ->where('organization_id', $organizationId)
            ->whereBelongsTo($user)
            ->pluck('id')
            ->all();
    }

    public function canView(User $user, CallLog $callLog): bool
    {
        if ($this->activeMembership($user, $callLog->organization_id) === null) {
            return false;
        }

        if ($this->canViewAll($user, $callLog->organization_id)) {
            return true;
        }

        $extensionIds = $this->accessibleExtensionIds($user, $callLog->organization_id);

        return ($callLog->caller_extension_id !== null && in_array($callLog->caller_extension_id, $extensionIds, true))
            || ($callLog->callee_extension_id !== null && in_array($callLog->callee_extension_id, $extensionIds, true));
    }

    private function activeMembership(User $user, Organization|int $organization): ?OrganizationMembership
    {
        return OrganizationMembership::query()
            ->where('organization_id', $organization instanceof Organization ? $organization->id : $organization)
            ->whereBelongsTo($user)
            ->where('status', MembershipStatus::Active->value)
            ->first();
    }
}
