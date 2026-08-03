<?php

namespace App\Policies;

use App\Enums\ExtensionType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class ExtensionPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->activeMembership($user, $organization) !== null;
    }

    public function view(User $user, Extension $extension): bool
    {
        return $this->canManage($user, $extension->organization_id)
            || ($extension->user_id === $user->id
                && $this->activeMembership($user, $extension->organization_id) !== null);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->canManage($user, $organization);
    }

    public function update(User $user, Extension $extension): bool
    {
        return $this->canManage($user, $extension->organization_id);
    }

    public function delete(User $user, Extension $extension): bool
    {
        return $this->canManage($user, $extension->organization_id);
    }

    public function rotateCredential(User $user, Extension $extension): bool
    {
        return $this->canManage($user, $extension->organization_id);
    }

    public function viewSipRegistration(User $user, Extension $extension): bool
    {
        return $extension->user_id === $user->id
            && in_array($extension->type, [ExtensionType::User, ExtensionType::Device], strict: true)
            && $this->activeMembership($user, $extension->organization_id) !== null;
    }

    public function restore(User $user, Extension $extension): bool
    {
        return false;
    }

    public function forceDelete(User $user, Extension $extension): bool
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

        // An individual's private workspace is not an administration tenant.
        // The same person may still administer a real organization when an
        // explicit owner/admin membership grants that authority.
        if ($organizationModel?->isPersonalWorkspace()) {
            return false;
        }

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
