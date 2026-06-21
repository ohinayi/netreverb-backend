<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipRole;
use App\Jobs\ProvisionSipSubscriber;
use App\Models\DialableNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SipCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExtensionApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_owner_can_create_an_extension_with_an_encrypted_one_time_secret(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        Queue::fake();
        Sanctum::actingAs($owner);

        $response = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/extensions",
            [
                'number' => '45101',
                'display_name' => 'Reception',
                'type' => 'user',
            ],
        );

        $response->assertCreated()
            ->assertJsonPath('data.number', '45101')
            ->assertJsonPath('data.realm', 'sip.classyra.com.ng')
            ->assertJsonPath('meta.display_once', true);

        $sipPassword = $response->json('meta.sip_password');
        $extension = Extension::query()->sole();
        $credential = SipCredential::query()->sole();
        $rawPassword = DB::table($credential->getTable())->value('password');

        $this->assertSame(48, strlen($sipPassword));
        $this->assertSame($sipPassword, $credential->password);
        $this->assertNotSame($sipPassword, $rawPassword);
        $this->assertSame($organization->id, $extension->organization_id);
        Queue::assertPushed(ProvisionSipSubscriber::class);
    }

    public function test_member_cannot_create_an_extension(): void
    {
        [$member, $organization] = $this->organizationWithUser(MembershipRole::Member);
        Sanctum::actingAs($member);

        $this->postJson("/api/v1/organizations/{$organization->public_id}/extensions", [
            'number' => '45102',
            'display_name' => 'Unauthorized',
            'type' => 'user',
        ])->assertForbidden();
    }

    public function test_extension_assignee_must_be_an_active_member_of_the_same_organization(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        $outsider = User::factory()->create();
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/organizations/{$organization->public_id}/extensions", [
            'number' => '45103',
            'display_name' => 'Restricted Assignment',
            'type' => 'user',
            'user_public_id' => $outsider->public_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_public_id');
    }

    public function test_owner_can_assign_an_extension_to_an_active_member(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        $member = User::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($member)->create();
        Queue::fake();
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/organizations/{$organization->public_id}/extensions", [
            'number' => '45107',
            'display_name' => 'Member Desk',
            'type' => 'user',
            'user_public_id' => $member->public_id,
        ])->assertCreated()->assertJsonPath('data.user_id', $member->public_id);

        $this->assertSame($member->id, Extension::query()->sole()->user_id);
    }

    public function test_a_number_cannot_collide_with_any_other_dialable_identity(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        DialableNumber::factory()->service()->for($organization)->create(['number' => '459666']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/organizations/{$organization->public_id}/extensions", [
            'number' => '459666',
            'display_name' => 'Collision',
            'type' => 'user',
        ])->assertUnprocessable()->assertJsonValidationErrors('number');
    }

    public function test_nested_binding_hides_an_extension_from_another_organization(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        $otherOrganization = Organization::factory()->create();
        $extension = Extension::factory()->for($otherOrganization)->create();
        Sanctum::actingAs($owner);

        $this->getJson(
            "/api/v1/organizations/{$organization->public_id}/extensions/{$extension->public_id}",
        )->assertNotFound();
    }

    public function test_member_only_lists_their_assigned_extensions(): void
    {
        [$member, $organization] = $this->organizationWithUser(MembershipRole::Member);
        $otherMember = User::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($otherMember)->create();
        $assignedExtension = Extension::factory()->for($organization)->for($member)->create();
        $otherExtension = Extension::factory()->for($organization)->for($otherMember)->create();
        Sanctum::actingAs($member);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/extensions")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assignedExtension->public_id)
            ->assertJsonMissing(['id' => $otherExtension->public_id]);
    }

    public function test_member_cannot_view_another_members_extension(): void
    {
        [$member, $organization] = $this->organizationWithUser(MembershipRole::Member);
        $otherMember = User::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($otherMember)->create();
        $otherExtension = Extension::factory()->for($organization)->for($otherMember)->create();
        Sanctum::actingAs($member);

        $this->getJson(
            "/api/v1/organizations/{$organization->public_id}/extensions/{$otherExtension->public_id}",
        )->assertForbidden();
    }

    public function test_assigned_member_can_get_their_automatic_sip_registration_settings(): void
    {
        [$member, $organization] = $this->organizationWithUser(MembershipRole::Member);
        $extension = Extension::factory()->for($organization)->for($member)->create();
        $extension->credential()->create(['password' => 'encrypted-sip-secret']);
        $extension->provisioningState()->create();
        Sanctum::actingAs($member);

        $this->getJson(
            "/api/v1/organizations/{$organization->public_id}/extensions/{$extension->public_id}/sip-registration",
        )->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.extension_id', $extension->public_id)
            ->assertJsonPath('data.username', $extension->dialableNumber->number)
            ->assertJsonPath('data.password', 'encrypted-sip-secret')
            ->assertJsonPath('data.secure_websocket_url', 'wss://sip.classyra.com.ng:7443');
    }

    public function test_even_an_admin_cannot_read_another_users_existing_sip_password(): void
    {
        [$admin, $organization] = $this->organizationWithUser(MembershipRole::Admin);
        $member = User::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($member)->create();
        $extension = Extension::factory()->for($organization)->for($member)->create();
        $extension->credential()->create(['password' => 'member-only-secret']);
        $extension->provisioningState()->create();
        Sanctum::actingAs($admin);

        $this->getJson(
            "/api/v1/organizations/{$organization->public_id}/extensions/{$extension->public_id}/sip-registration",
        )->assertForbidden();
    }

    public function test_owner_can_rotate_a_sip_credential(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        Queue::fake();
        Sanctum::actingAs($owner);
        $createResponse = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/extensions",
            ['number' => '45104', 'display_name' => 'Desk', 'type' => 'user'],
        );
        $extension = Extension::query()->sole();
        $firstPassword = $createResponse->json('meta.sip_password');

        $response = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/extensions/{$extension->public_id}/credentials/rotate",
        );

        $response->assertOk()->assertJsonPath('data.display_once', true);
        $this->assertNotSame($firstPassword, $response->json('data.sip_password'));
        $this->assertSame(2, $extension->credential()->value('version'));
        $this->assertSame(2, $extension->provisioningState()->value('desired_revision'));
        Queue::assertPushed(ProvisionSipSubscriber::class, 2);
    }

    /** @return array{User, Organization} */
    private function organizationWithUser(MembershipRole $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($user)->create([
            'role' => $role,
        ]);

        return [$user, $organization];
    }
}
