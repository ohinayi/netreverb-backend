<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\ConferenceRecording;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class ConferenceRecordingPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->activeMembership($user, $organization) !== null;
    }

    public function view(User $user, ConferenceRecording $conferenceRecording): bool
    {
        return false;
    }

    public function create(User $user, Organization $organization): bool
    {
        return false;
    }

    public function update(User $user, ConferenceRecording $conferenceRecording): bool
    {
        return false;
    }

    public function delete(User $user, ConferenceRecording $conferenceRecording): bool
    {
        return $this->canManage($user, $conferenceRecording->conferenceRoom()->value('organization_id') ?? 0);
    }

    public function restore(User $user, ConferenceRecording $conferenceRecording): bool
    {
        return false;
    }

    public function forceDelete(User $user, ConferenceRecording $conferenceRecording): bool
    {
        return false;
    }

    private function canManage(User $user, int $organizationId): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return in_array(
            $this->activeMembership($user, $organizationId)?->role,
            [MembershipRole::Owner, MembershipRole::Admin, MembershipRole::TelephonyAdmin, MembershipRole::Supervisor],
            true,
        );
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
