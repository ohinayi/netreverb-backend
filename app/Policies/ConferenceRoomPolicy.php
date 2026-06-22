<?php

namespace App\Policies;

use App\Enums\ConferenceRoomStatus;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\ConferenceRoom;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class ConferenceRoomPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->activeMembership($user, $organization) !== null;
    }

    public function view(User $user, ConferenceRoom $conferenceRoom): bool
    {
        return $conferenceRoom->host_user_id === $user->id
            || $this->activeMembership($user, $conferenceRoom->organization_id) !== null;
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->canManage($user, $organization);
    }

    public function invite(User $user, ConferenceRoom $conferenceRoom): bool
    {
        return $conferenceRoom->status === ConferenceRoomStatus::Active
            && $this->canManage($user, $conferenceRoom->organization_id);
    }

    public function join(User $user, ConferenceRoom $conferenceRoom): bool
    {
        return $conferenceRoom->status === ConferenceRoomStatus::Active
            && $this->activeMembership($user, $conferenceRoom->organization_id) !== null;
    }

    public function leave(User $user, ConferenceRoom $conferenceRoom): bool
    {
        return $conferenceRoom->status === ConferenceRoomStatus::Active
            && $this->activeMembership($user, $conferenceRoom->organization_id) !== null;
    }

    public function end(User $user, ConferenceRoom $conferenceRoom): bool
    {
        return $conferenceRoom->status === ConferenceRoomStatus::Active
            && (
                $conferenceRoom->host_user_id === $user->id
                || $this->canManage($user, $conferenceRoom->organization_id)
            );
    }

    public function update(User $user, ConferenceRoom $conferenceRoom): bool
    {
        return $this->end($user, $conferenceRoom);
    }

    public function delete(User $user, ConferenceRoom $conferenceRoom): bool
    {
        return $this->end($user, $conferenceRoom);
    }

    public function restore(User $user, ConferenceRoom $conferenceRoom): bool
    {
        return false;
    }

    public function forceDelete(User $user, ConferenceRoom $conferenceRoom): bool
    {
        return false;
    }

    private function canManage(User $user, Organization|int $organization): bool
    {
        return in_array(
            $this->activeMembership($user, $organization)?->role,
            [MembershipRole::Owner, MembershipRole::Admin],
            true,
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
