<?php

namespace App\Policies;

use App\Enums\CommunityMembershipRole;
use App\Enums\CommunityMembershipStatus;
use App\Enums\CommunityVisibility;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\User;

class CommunityPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Community $community): bool
    {
        return $community->visibility === CommunityVisibility::Public
            || $community->owner_user_id === $user->id
            || CommunityMembership::query()
                ->where('community_id', $community->id)
                ->where('user_id', $user->id)
                ->where('status', CommunityMembershipStatus::Active->value)
                ->exists();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Community $community): bool
    {
        return $community->owner_user_id === $user->id
            || $this->isAdmin($user, $community);
    }

    public function delete(User $user, Community $community): bool
    {
        return $community->owner_user_id === $user->id;
    }

    public function join(User $user, Community $community): bool
    {
        return $community->visibility !== CommunityVisibility::Private;
    }

    public function invite(User $user, Community $community): bool
    {
        return $community->owner_user_id === $user->id
            || $this->isAdmin($user, $community);
    }

    public function restore(User $user, Community $community): bool
    {
        return false;
    }

    public function forceDelete(User $user, Community $community): bool
    {
        return false;
    }

    private function isAdmin(User $user, Community $community): bool
    {
        return CommunityMembership::query()
            ->where('community_id', $community->id)
            ->where('user_id', $user->id)
            ->where('status', CommunityMembershipStatus::Active->value)
            ->whereIn('role', [CommunityMembershipRole::Admin->value, CommunityMembershipRole::Owner->value])
            ->exists();
    }
}
