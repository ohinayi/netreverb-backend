<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CallRecordingAnnouncementTarget;
use App\Enums\MembershipRole;
use App\Models\Department;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_authentication_is_required(): void
    {
        $this->getJson('/api/v1/organizations')->assertUnauthorized();
    }

    public function test_user_can_create_an_organization_and_becomes_its_owner(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/organizations', [
            'name' => 'Classyra Hospital',
            'slug' => 'classyra-hospital',
            'timezone' => 'Africa/Lagos',
            'locale' => 'en',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Classyra Hospital')
            ->assertJsonPath('data.slug', 'classyra-hospital');

        $organization = Organization::query()->sole();
        $membership = OrganizationMembership::query()->sole();

        $this->assertSame($user->id, $membership->user_id);
        $this->assertSame($organization->id, $membership->organization_id);
        $this->assertSame(MembershipRole::Owner, $membership->role);
    }

    public function test_users_cannot_access_another_organization(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->owner()->for($organization)->for($owner)->create();
        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/organizations/{$organization->public_id}")
            ->assertForbidden();
        $this->getJson('/api/v1/organizations')
            ->assertOk()
            ->assertJsonMissing(['id' => $organization->public_id]);
    }

    public function test_admin_can_update_but_member_cannot_update_an_organization(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        OrganizationMembership::factory()->for($organization)->for($member)->create();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/organizations/{$organization->public_id}", [
            'name' => 'Updated Organization',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Organization');

        Sanctum::actingAs($member);
        $this->patchJson("/api/v1/organizations/{$organization->public_id}", [
            'name' => 'Unauthorized Change',
        ])->assertForbidden();
    }

    public function test_admin_can_update_call_recording_announcement_settings(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/organizations/{$organization->public_id}", [
            'settings' => [
                'call_recording_announcement' => [
                    'enabled' => true,
                    'target' => CallRecordingAnnouncementTarget::Caller->value,
                    'audio_path' => '/usr/local/freeswitch/sounds/custom/recording_notice.wav',
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.settings.call_recording_announcement.enabled', true)
            ->assertJsonPath('data.settings.call_recording_announcement.target', CallRecordingAnnouncementTarget::Caller->value)
            ->assertJsonPath('data.settings.call_recording_announcement.audio_path', '/usr/local/freeswitch/sounds/custom/recording_notice.wav');

        $organization->refresh();

        $this->assertSame([
            'call_recording_announcement' => [
                'enabled' => true,
                'target' => CallRecordingAnnouncementTarget::Caller->value,
                'audio_path' => '/usr/local/freeswitch/sounds/custom/recording_notice.wav',
            ],
        ], $organization->settings);
    }

    public function test_admin_can_create_a_department_but_member_cannot(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        OrganizationMembership::factory()->for($organization)->for($member)->create();

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/organizations/{$organization->public_id}/departments", [
            'name' => 'Engineering',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Engineering')
            ->assertJsonPath('data.slug', 'engineering');

        $this->assertDatabaseHas('departments', [
            'organization_id' => $organization->id,
            'name' => 'Engineering',
        ]);

        Sanctum::actingAs($member);
        $this->postJson("/api/v1/organizations/{$organization->public_id}/departments", [
            'name' => 'Sales',
        ])->assertForbidden();
    }

    public function test_admin_can_list_and_update_departments(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Support']);

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/organizations/{$organization->public_id}/departments")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Support');

        $this->patchJson(
            "/api/v1/organizations/{$organization->public_id}/departments/{$department->public_id}",
            ['name' => 'Customer Support'],
        )->assertOk()->assertJsonPath('data.name', 'Customer Support');
    }

    public function test_admin_can_invite_an_existing_user_as_an_organization_member(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        $invitee = User::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/organizations/{$organization->public_id}/members", [
            'user_public_id' => $invitee->public_id,
        ])->assertCreated()
            ->assertJsonPath('data.user.id', $invitee->public_id)
            ->assertJsonPath('data.status', 'invited');

        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $invitee->id,
            'status' => 'invited',
        ]);
    }

    public function test_admin_can_invite_a_new_member_by_email_and_a_user_account_is_created(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        $department = Department::factory()->for($organization)->create();

        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/v1/organizations/{$organization->public_id}/members", [
            'email' => 'newhire@example.com',
            'name' => 'New Hire',
            'department_public_id' => $department->public_id,
        ])->assertCreated();

        $response->assertJsonPath('data.department.id', $department->public_id);

        $user = User::query()->where('email', 'newhire@example.com')->sole();
        $this->assertSame('New Hire', $user->name);

        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'department_id' => $department->id,
        ]);
    }

    public function test_member_cannot_invite_organization_members(): void
    {
        $organization = Organization::factory()->create();
        $member = User::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($member)->create();

        Sanctum::actingAs($member);
        $this->postJson("/api/v1/organizations/{$organization->public_id}/members", [
            'email' => 'someone@example.com',
        ])->assertForbidden();
    }
}
