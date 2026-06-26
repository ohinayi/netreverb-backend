<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CallStatus;
use App\Enums\MembershipRole;
use App\Models\CallLog;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CallLogApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_member_can_create_a_call_log(): void
    {
        [$member, $organization] = $this->organizationWithUser(MembershipRole::Member);
        $callerExtension = Extension::factory()->for($organization)->for($member)->create();
        Sanctum::actingAs($member);

        $response = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/call-logs",
            [
                'caller_extension_public_id' => $callerExtension->public_id,
                'caller_number' => $callerExtension->dialableNumber->number,
                'callee_number' => '+1234567890',
                'status' => CallStatus::Ringing->value,
                'started_at' => now()->toDateTimeString(),
            ]
        );

        $response->assertCreated()
            ->assertJsonPath('data.caller_number', $callerExtension->dialableNumber->number)
            ->assertJsonPath('data.callee_number', '+1234567890')
            ->assertJsonPath('data.status', CallStatus::Ringing->value);

        $this->assertDatabaseHas('call_logs', [
            'organization_id' => $organization->id,
            'caller_extension_id' => $callerExtension->id,
            'caller_number' => $callerExtension->dialableNumber->number,
            'callee_number' => '+1234567890',
        ]);
    }

    public function test_owner_can_list_all_call_logs_in_organization(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        CallLog::factory()->count(3)->for($organization)->create();

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs");

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_member_only_lists_their_own_call_logs(): void
    {
        [$member, $organization] = $this->organizationWithUser(MembershipRole::Member);
        $otherMember = User::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($otherMember)->create();

        $memberExtension = Extension::factory()->for($organization)->for($member)->create();
        $otherExtension = Extension::factory()->for($organization)->for($otherMember)->create();

        // Call log belonging to member
        $myCall = CallLog::factory()->for($organization)->create([
            'caller_extension_id' => $memberExtension->id,
            'caller_number' => $memberExtension->dialableNumber->number,
        ]);

        // Call log not belonging to member
        $otherCall = CallLog::factory()->for($organization)->create([
            'caller_extension_id' => $otherExtension->id,
            'caller_number' => $otherExtension->dialableNumber->number,
        ]);

        Sanctum::actingAs($member);

        $response = $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $myCall->public_id)
            ->assertJsonMissing(['id' => $otherCall->public_id]);
    }

    public function test_owner_can_view_any_call_log(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        $callLog = CallLog::factory()->for($organization)->create();

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $callLog->public_id);
    }

    public function test_member_cannot_view_others_call_log(): void
    {
        [$member, $organization] = $this->organizationWithUser(MembershipRole::Member);
        $otherMember = User::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($otherMember)->create();

        $otherExtension = Extension::factory()->for($organization)->for($otherMember)->create();
        $callLog = CallLog::factory()->for($organization)->create([
            'caller_extension_id' => $otherExtension->id,
        ]);

        Sanctum::actingAs($member);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}")
            ->assertForbidden();
    }

    public function test_member_can_view_their_own_call_log(): void
    {
        [$member, $organization] = $this->organizationWithUser(MembershipRole::Member);
        $memberExtension = Extension::factory()->for($organization)->for($member)->create();
        $callLog = CallLog::factory()->for($organization)->create([
            'caller_extension_id' => $memberExtension->id,
        ]);

        Sanctum::actingAs($member);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $callLog->public_id);
    }

    public function test_owner_can_update_call_log_status_and_recording(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        $callLog = CallLog::factory()->for($organization)->create([
            'status' => CallStatus::Ringing->value,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->putJson(
            "/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}",
            [
                'status' => CallStatus::Completed->value,
                'duration' => 120,
                'recording_url' => 'https://storage.netreverb.com/recordings/test.mp3',
                'recording_duration' => 120,
                'recording_size' => 1048576,
                'ended_at' => now()->toDateTimeString(),
            ]
        );

        $response->assertOk()
            ->assertJsonPath('data.status', CallStatus::Completed->value)
            ->assertJsonPath('data.duration', 120)
            ->assertJsonPath('data.recording.url', 'https://storage.netreverb.com/recordings/test.mp3')
            ->assertJsonPath('data.recording.size', 1048576);

        $this->assertDatabaseHas('call_logs', [
            'id' => $callLog->id,
            'status' => CallStatus::Completed->value,
            'duration' => 120,
            'recording_url' => 'https://storage.netreverb.com/recordings/test.mp3',
        ]);
    }

    public function test_member_cannot_update_others_call_log(): void
    {
        [$member, $organization] = $this->organizationWithUser(MembershipRole::Member);
        $otherMember = User::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($otherMember)->create();

        $otherExtension = Extension::factory()->for($organization)->for($otherMember)->create();
        $callLog = CallLog::factory()->for($organization)->create([
            'caller_extension_id' => $otherExtension->id,
        ]);

        Sanctum::actingAs($member);

        $this->putJson(
            "/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}",
            ['status' => CallStatus::Completed->value]
        )->assertForbidden();
    }

    public function test_owner_can_delete_call_log(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        $callLog = CallLog::factory()->for($organization)->create();

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}")
            ->assertNoContent();

        $this->assertSoftDeleted($callLog);
    }

    public function test_member_cannot_delete_call_log(): void
    {
        [$member, $organization] = $this->organizationWithUser(MembershipRole::Member);
        $callLog = CallLog::factory()->for($organization)->create();

        Sanctum::actingAs($member);

        $this->deleteJson("/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}")
            ->assertForbidden();
    }

    public function test_nested_binding_hides_call_log_from_another_organization(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        $otherOrganization = Organization::factory()->create();
        $callLog = CallLog::factory()->for($otherOrganization)->create();

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}")
            ->assertNotFound();
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
