<?php

namespace App\Actions\Auth;

use App\Actions\Extensions\ProvisionVerifiedUserExtension;
use App\Actions\Organizations\SyncOrganizationMemberFriendships;
use App\Enums\MembershipStatus;
use App\Models\OrganizationMembership;
use App\Models\User;

class FinalizeUserLogin
{
    public function __construct(
        private SyncOrganizationMemberFriendships $syncFriendships,
        private ProvisionVerifiedUserExtension $provisionExtension,
    ) {}

    public function execute(User $user): void
    {
        $user->update(['last_login_at' => now()]);

        // Reconcile legacy invitations created before membership acceptance was
        // moved to the organization administrator. A verified user should
        // never remain stranded in an invited state after signing in.
        if ($user->hasVerifiedEmail()) {
            $memberships = OrganizationMembership::query()
                ->whereBelongsTo($user)
                ->where('status', MembershipStatus::Invited->value)
                ->with('organization')
                ->get();

            foreach ($memberships as $membership) {
                $membership->update([
                    'status' => MembershipStatus::Active->value,
                    'joined_at' => $membership->joined_at ?? now(),
                ]);
                $this->syncFriendships->execute($membership->organization, $user);
            }
        }

        $this->provisionExtension->execute($user);
    }
}
