<?php

namespace App\Actions\Organizations;

use App\Enums\FriendshipStatus;
use App\Enums\MembershipStatus;
use App\Models\Friendship;
use App\Models\Organization;
use App\Models\User;

class SyncOrganizationMemberFriendships
{
    /** Make an active member an accepted contact of all other active members. */
    public function execute(Organization $organization, User $member): void
    {
        $memberIds = $organization->memberships()
            ->where('status', MembershipStatus::Active->value)
            ->where('user_id', '!=', $member->id)
            ->pluck('user_id');

        foreach ($memberIds as $otherUserId) {
            $friendship = Friendship::query()
                ->where(function ($query) use ($member, $otherUserId): void {
                    $query->where('requester_id', $member->id)
                        ->where('addressee_id', $otherUserId);
                })
                ->orWhere(function ($query) use ($member, $otherUserId): void {
                    $query->where('requester_id', $otherUserId)
                        ->where('addressee_id', $member->id);
                })
                ->first();

            if ($friendship === null) {
                Friendship::query()->create([
                    'requester_id' => $member->id,
                    'addressee_id' => $otherUserId,
                    'status' => FriendshipStatus::Accepted,
                    'requested_at' => now(),
                    'responded_at' => now(),
                ]);

                continue;
            }

            if ($friendship->status !== FriendshipStatus::Accepted) {
                $friendship->update([
                    'status' => FriendshipStatus::Accepted,
                    'responded_at' => now(),
                ]);
            }
        }
    }
}
