<?php

namespace App\Actions\Extensions;

use App\Enums\ExtensionProvisioningMode;
use App\Enums\ExtensionType;
use App\Enums\MembershipStatus;
use App\Models\Extension;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProvisionVerifiedUserExtension
{
    public function __construct(
        private AllocateAutomaticExtensionNumber $allocateNumber,
        private CreateExtension $createExtension,
    ) {}

    public function execute(User $user): ?Extension
    {
        return DB::transaction(function () use ($user): ?Extension {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! $lockedUser->hasVerifiedEmail()) {
                return null;
            }

            $memberships = OrganizationMembership::query()
                ->with('organization')
                ->whereBelongsTo($lockedUser)
                ->where('status', MembershipStatus::Active->value)
                ->where('auto_assign_extension', true)
                ->whereHas('organization', fn ($query) => $query
                    ->where('extension_provisioning_mode', ExtensionProvisioningMode::Automatic->value))
                ->oldest('id')
                ->get();

            $membership = $memberships->first(
                fn (OrganizationMembership $membership): bool => ! Extension::query()
                    ->whereBelongsTo($membership->organization)
                    ->whereBelongsTo($lockedUser)
                    ->exists(),
            );

            if ($membership === null) {
                return null;
            }

            return $this->createExtension->execute($membership->organization, [
                'number' => $this->allocateNumber->execute(),
                'display_name' => $lockedUser->name,
                'type' => ExtensionType::User,
                'user_public_id' => $lockedUser->public_id,
            ])->extension;
        }, attempts: 3);
    }
}
