<?php

namespace App\Actions\Organizations;

use App\Actions\Extensions\ProvisionVerifiedUserExtension;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Department;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AddOrganizationMember
{
    public function __construct(
        private SyncOrganizationMemberFriendships $syncFriendships,
        private ProvisionVerifiedUserExtension $provisionExtension,
    ) {}

    /**
     * @param  array{user_public_id?: ?string, email?: ?string, name?: ?string, department_public_id?: ?string, role?: ?string, assign_extension?: bool}  $attributes
     */
    public function execute(Organization $organization, User $invitedBy, array $attributes): OrganizationMembership
    {
        $membership = DB::transaction(function () use ($organization, $invitedBy, $attributes): OrganizationMembership {
            $user = $this->resolveUser($attributes);

            $department = null;
            if (! empty($attributes['department_public_id'])) {
                $department = Department::query()
                    ->where('public_id', $attributes['department_public_id'])
                    ->whereBelongsTo($organization)
                    ->firstOrFail();
            }

            $membership = OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                [
                    'department_id' => $department?->id,
                    'auto_assign_extension' => (bool) ($attributes['assign_extension'] ?? false),
                    'invited_by' => $invitedBy->id,
                    'role' => isset($attributes['role'])
                        ? MembershipRole::from($attributes['role'])
                        : MembershipRole::Agent,
                    // Organization admins control membership acceptance. The
                    // invitee only needs to complete the secure password-reset
                    // link before signing in to their assigned workspace.
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                ],
            );

            $this->syncFriendships->execute($organization, $user);

            return $membership;
        });

        $membership->loadMissing('user');
        if ($membership->auto_assign_extension && $membership->user->hasVerifiedEmail()) {
            $this->provisionExtension->execute($membership->user);
        }

        return $membership;
    }

    /**
     * @param  array{user_public_id?: ?string, email?: ?string, name?: ?string}  $attributes
     */
    private function resolveUser(array $attributes): User
    {
        if (! empty($attributes['user_public_id'])) {
            return User::query()->where('public_id', $attributes['user_public_id'])->firstOrFail();
        }

        $email = $attributes['email'] ?? null;

        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null) {
            return $existing;
        }

        $user = User::query()->create([
            'name' => $attributes['name'] ?? Str::before($email, '@'),
            'email' => $email,
            'password' => Str::random(32),
        ]);

        Password::sendResetLink(['email' => $user->email]);

        return $user;
    }
}
