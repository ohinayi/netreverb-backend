<?php

namespace App\Actions\Auth;

use App\Actions\Extensions\ProvisionVerifiedUserExtension;
use App\Actions\Organizations\CreateOrganization;
use App\Enums\AccountType;
use App\Enums\ExtensionProvisioningMode;
use App\Enums\MembershipStatus;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// OAuth sign-ins can't ask account-type/workspace questions before the
// provider redirect the way the register form does, so a Google/etc. user
// lands with no organization at all. This finishes that same setup - same
// CreateOrganization call the register flow makes - once the user is back
// and can answer those questions.
class CompleteUserOrganization
{
    public function __construct(
        private CreateOrganization $createOrganization,
        private ProvisionVerifiedUserExtension $provisionExtension,
    ) {}

    /** @param  array{account_type: string, workspace_name?: string|null, assign_extension?: bool}  $attributes */
    public function execute(User $user, array $attributes): ?User
    {
        return DB::transaction(function () use ($user, $attributes): ?User {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            $alreadyOnboarded = OrganizationMembership::query()
                ->whereBelongsTo($lockedUser)
                ->where('status', MembershipStatus::Active->value)
                ->exists();

            if ($alreadyOnboarded) {
                return null;
            }

            $accountType = AccountType::from($attributes['account_type']);

            $lockedUser->forceFill([
                'account_type' => $accountType,
                'terms_accepted_at' => $lockedUser->terms_accepted_at ?? now(),
            ])->save();

            $workspaceName = $accountType === AccountType::Community
                ? (string) $attributes['workspace_name']
                : $lockedUser->name.' Workspace';

            $this->createOrganization->execute($lockedUser, [
                'name' => $workspaceName,
                'slug' => Str::slug($workspaceName).'-'.Str::lower(Str::random(8)),
                'extension_provisioning_mode' => ExtensionProvisioningMode::Automatic,
                'timezone' => $lockedUser->timezone,
                'locale' => $lockedUser->locale,
                'settings' => ['kind' => $accountType->value],
                'assign_owner_extension' => $accountType === AccountType::Individual
                    || (bool) ($attributes['assign_extension'] ?? false),
            ]);

            $this->provisionExtension->execute($lockedUser);

            return $lockedUser;
        }, attempts: 3);
    }
}
