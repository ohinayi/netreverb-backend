<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\Voicemail;
use App\Services\Authorization\CallLogVisibility;

class VoicemailPolicy
{
    public function __construct(private readonly CallLogVisibility $visibility) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->activeMembership($user, $organization) !== null;
    }

    public function viewAll(User $user, Organization $organization): bool
    {
        return $this->visibility->canViewAll($user, $organization);
    }

    public function view(User $user, Voicemail $voicemail): bool
    {
        if ($user->isSuperAdmin() || $this->visibility->canViewAll($user, $voicemail->organization_id)) {
            return true;
        }

        return in_array(
            $voicemail->extension_id,
            $this->visibility->accessibleExtensionIds($user, $voicemail->organization_id),
            true,
        );
    }

    public function delete(User $user, Voicemail $voicemail): bool
    {
        return $this->view($user, $voicemail);
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
