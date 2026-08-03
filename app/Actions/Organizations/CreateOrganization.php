<?php

namespace App\Actions\Organizations;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateOrganization
{
    public function execute(User $owner, array $attributes): Organization
    {
        return DB::transaction(function () use ($owner, $attributes): Organization {
            $assignOwnerExtension = (bool) Arr::pull($attributes, 'assign_owner_extension', false);
            $organization = Organization::query()->create($attributes);

            $workspaceName = $organization->isPersonalWorkspace()
                ? 'Personal workspace'
                : $organization->name.' workspace';
            $workspace = $organization->workspaces()->create([
                'name' => $workspaceName,
                'slug' => 'default',
                'kind' => $organization->isPersonalWorkspace() ? 'personal' : 'team',
                'status' => 'active',
                'settings' => ['system_default' => true],
            ]);

            $organization->memberships()->create([
                'user_id' => $owner->id,
                'workspace_id' => $workspace->id,
                'auto_assign_extension' => $assignOwnerExtension,
                'role' => MembershipRole::Owner,
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ]);

            return $organization;
        });
    }
}
