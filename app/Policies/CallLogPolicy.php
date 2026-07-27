<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Authorization\CallLogVisibility;

class CallLogPolicy
{
    public function __construct(private readonly CallLogVisibility $visibility) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->activeMembership($user, $organization) !== null;
    }

    public function viewAll(User $user, Organization $organization): bool
    {
        return $this->visibility->canViewAll($user, $organization);
    }

    public function view(User $user, CallLog $callLog): bool
    {
        return $this->visibility->canView($user, $callLog);
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
        if ($user->isSuperAdmin()) {
            return true;
        }

        $organizationModel = $organization instanceof Organization
            ? $organization
            : Organization::query()->find($organization);

        // A personal workspace can place and receive calls, but it cannot use
        // organization controls such as transferring a live call to a team.
        if ($organizationModel?->isPersonalWorkspace()) {
            return false;
        }

        return in_array(
            $this->activeMembership($user, $organization)?->role,
            [
                MembershipRole::Owner,
                MembershipRole::Admin,
                MembershipRole::TelephonyAdmin,
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
