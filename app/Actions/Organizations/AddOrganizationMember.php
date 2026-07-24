<?php

namespace App\Actions\Organizations;

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
    /**
     * @param  array{user_public_id?: ?string, email?: ?string, name?: ?string, department_public_id?: ?string, role?: ?string}  $attributes
     */
    public function execute(Organization $organization, User $invitedBy, array $attributes): OrganizationMembership
    {
        return DB::transaction(function () use ($organization, $invitedBy, $attributes): OrganizationMembership {
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
                    'invited_by' => $invitedBy->id,
                    'role' => isset($attributes['role'])
                        ? MembershipRole::from($attributes['role'])
                        : MembershipRole::Member,
                    'status' => MembershipStatus::Invited,
                    'joined_at' => null,
                ],
            );

            return $membership;
        });
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
