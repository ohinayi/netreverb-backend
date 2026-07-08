<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CallRecordingStatus;
use App\Enums\CallStatus;
use App\Enums\MembershipRole;
use App\Models\CallLog;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Telephony\FreeSwitchCallUuidSynchronizer;
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
                'freeswitch_uuid' => 'fs-call-uuid-1234',
                'status' => CallStatus::Ringing->value,
                'started_at' => now()->toDateTimeString(),
            ]
        );

        $response->assertCreated()
            ->assertJsonPath('data.caller_number', $callerExtension->dialableNumber->number)
            ->assertJsonPath('data.callee_number', '+1234567890')
            ->assertJsonPath('data.freeswitch_uuid', 'fs-call-uuid-1234')
            ->assertJsonPath('data.status', CallStatus::Ringing->value);

        $this->assertDatabaseHas('call_logs', [
            'organization_id' => $organization->id,
            'caller_extension_id' => $callerExtension->id,
            'caller_number' => $callerExtension->dialableNumber->number,
            'callee_number' => '+1234567890',
            'freeswitch_uuid' => 'fs-call-uuid-1234',
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

    public function test_index_returns_only_the_latest_ten_call_logs(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        CallLog::factory()->count(12)->for($organization)->create();

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs");

        $response->assertOk()
            ->assertJsonCount(10, 'data');
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

    public function test_show_can_trigger_uuid_sync_for_an_active_call_without_uuid(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        $callLog = CallLog::factory()->for($organization)->create([
            'status' => CallStatus::Ringing->value,
            'freeswitch_uuid' => null,
        ]);

        $this->app->instance(
            FreeSwitchCallUuidSynchronizer::class,
            tap($this->mock(FreeSwitchCallUuidSynchronizer::class), function ($mock) use ($callLog): void {
                $mock->shouldReceive('syncOnce')
                    ->once()
                    ->andReturnUsing(function () use ($callLog): int {
                        $callLog->forceFill([
                            'freeswitch_uuid' => 'fs-live-sync-uuid',
                        ])->save();

                        return 1;
                    });
            }),
        );

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}");

        $response->assertOk()
            ->assertJsonPath('data.freeswitch_uuid', 'fs-live-sync-uuid');
    }

    public function test_index_does_not_trigger_uuid_sync_for_active_calls_without_uuid(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        CallLog::factory()->for($organization)->create([
            'status' => CallStatus::Ringing->value,
            'freeswitch_uuid' => null,
        ]);

        $this->app->instance(
            FreeSwitchCallUuidSynchronizer::class,
            tap($this->mock(FreeSwitchCallUuidSynchronizer::class), function ($mock): void {
                $mock->shouldReceive('syncOnce')
                    ->never();
            }),
        );

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs");

        $response->assertOk()
            ->assertJsonPath('data.0.freeswitch_uuid', null);
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

    public function test_callee_sees_canceled_call_as_missed_incoming_call(): void
    {
        [$caller, $organization] = $this->organizationWithUser(MembershipRole::Member);
        $callee = User::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($callee)->create([
            'role' => MembershipRole::Member,
        ]);

        $callerExtension = Extension::factory()->for($organization)->for($caller)->create();
        $calleeExtension = Extension::factory()->for($organization)->for($callee)->create();

        $callLog = CallLog::factory()->for($organization)->create([
            'caller_extension_id' => $callerExtension->id,
            'callee_extension_id' => $calleeExtension->id,
            'caller_number' => $callerExtension->dialableNumber->number,
            'callee_number' => $calleeExtension->dialableNumber->number,
            'status' => CallStatus::Canceled->value,
            'duration' => 0,
        ]);

        Sanctum::actingAs($callee);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}")
            ->assertOk()
            ->assertJsonPath('data.direction', 'incoming')
            ->assertJsonPath('data.party_status', 'missed')
            ->assertJsonPath('data.is_missed', true)
            ->assertJsonPath('data.is_answered', false);
    }

    public function test_caller_sees_canceled_call_as_canceled_outgoing_call(): void
    {
        [$caller, $organization] = $this->organizationWithUser(MembershipRole::Member);
        $callee = User::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($callee)->create([
            'role' => MembershipRole::Member,
        ]);

        $callerExtension = Extension::factory()->for($organization)->for($caller)->create();
        $calleeExtension = Extension::factory()->for($organization)->for($callee)->create();

        $callLog = CallLog::factory()->for($organization)->create([
            'caller_extension_id' => $callerExtension->id,
            'callee_extension_id' => $calleeExtension->id,
            'caller_number' => $callerExtension->dialableNumber->number,
            'callee_number' => $calleeExtension->dialableNumber->number,
            'status' => CallStatus::Canceled->value,
            'duration' => 0,
        ]);

        Sanctum::actingAs($caller);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}")
            ->assertOk()
            ->assertJsonPath('data.direction', 'outgoing')
            ->assertJsonPath('data.party_status', 'canceled')
            ->assertJsonPath('data.is_missed', false)
            ->assertJsonPath('data.is_answered', false);
    }

    public function test_completed_call_is_reported_as_answered_for_both_parties(): void
    {
        [$caller, $organization] = $this->organizationWithUser(MembershipRole::Member);
        $callee = User::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($callee)->create([
            'role' => MembershipRole::Member,
        ]);

        $callerExtension = Extension::factory()->for($organization)->for($caller)->create();
        $calleeExtension = Extension::factory()->for($organization)->for($callee)->create();

        $callLog = CallLog::factory()->for($organization)->create([
            'caller_extension_id' => $callerExtension->id,
            'callee_extension_id' => $calleeExtension->id,
            'caller_number' => $callerExtension->dialableNumber->number,
            'callee_number' => $calleeExtension->dialableNumber->number,
            'status' => CallStatus::Completed->value,
            'duration' => 45,
        ]);

        Sanctum::actingAs($caller);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}")
            ->assertOk()
            ->assertJsonPath('data.direction', 'outgoing')
            ->assertJsonPath('data.party_status', 'answered')
            ->assertJsonPath('data.is_answered', true);

        Sanctum::actingAs($callee);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}")
            ->assertOk()
            ->assertJsonPath('data.direction', 'incoming')
            ->assertJsonPath('data.party_status', 'answered')
            ->assertJsonPath('data.is_answered', true);
    }

    public function test_owner_sees_an_external_call_as_outgoing_based_on_call_parties(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        $caller = User::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($caller)->create([
            'role' => MembershipRole::Member,
        ]);

        $callerExtension = Extension::factory()->for($organization)->for($caller)->create();
        $callLog = CallLog::factory()->for($organization)->create([
            'caller_extension_id' => $callerExtension->id,
            'caller_number' => $callerExtension->dialableNumber->number,
            'callee_extension_id' => null,
            'callee_number' => '+1234567890',
            'status' => CallStatus::Completed->value,
        ]);

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}")
            ->assertOk()
            ->assertJsonPath('data.direction', 'outgoing')
            ->assertJsonPath('data.party_status', 'answered');
    }

    public function test_index_can_filter_incoming_outgoing_and_missed_calls(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        $callee = User::factory()->create();
        $caller = User::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($callee)->create([
            'role' => MembershipRole::Member,
        ]);
        OrganizationMembership::factory()->for($organization)->for($caller)->create([
            'role' => MembershipRole::Member,
        ]);

        $incomingExtension = Extension::factory()->for($organization)->for($callee)->create();
        $outgoingExtension = Extension::factory()->for($organization)->for($caller)->create();

        $incomingCall = CallLog::factory()->for($organization)->create([
            'caller_extension_id' => null,
            'callee_extension_id' => $incomingExtension->id,
            'caller_number' => '+19876543210',
            'callee_number' => $incomingExtension->dialableNumber->number,
            'status' => CallStatus::Completed->value,
        ]);

        $outgoingCall = CallLog::factory()->for($organization)->create([
            'caller_extension_id' => $outgoingExtension->id,
            'callee_extension_id' => null,
            'caller_number' => $outgoingExtension->dialableNumber->number,
            'callee_number' => '+10987654321',
            'status' => CallStatus::Completed->value,
        ]);

        $missedCall = CallLog::factory()->for($organization)->create([
            'caller_extension_id' => null,
            'callee_extension_id' => $incomingExtension->id,
            'caller_number' => '+12125551234',
            'callee_number' => $incomingExtension->dialableNumber->number,
            'status' => CallStatus::NoAnswer->value,
        ]);

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs?filter=incoming")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $incomingCall->public_id])
            ->assertJsonFragment(['id' => $missedCall->public_id]);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs?filter=outgoing")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $outgoingCall->public_id);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs?filter=missed")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $missedCall->public_id)
            ->assertJsonPath('data.0.is_missed', true);
    }

    public function test_owner_can_update_call_log_status_and_recording(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        $callLog = CallLog::factory()->for($organization)->create([
            'status' => CallStatus::Ringing->value,
            'recording_status' => CallRecordingStatus::Completed->value,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->putJson(
            "/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}",
            [
                'status' => CallStatus::Completed->value,
                'duration' => 120,
                'freeswitch_uuid' => 'fs-call-uuid-1234',
                'recording_url' => 'https://storage.netreverb.com/recordings/test.mp3',
                'recording_duration' => 120,
                'recording_size' => 1048576,
                'ended_at' => now()->toDateTimeString(),
            ]
        );

        $response->assertOk()
            ->assertJsonPath('data.status', CallStatus::Completed->value)
            ->assertJsonPath('data.duration', 120)
            ->assertJsonPath('data.freeswitch_uuid', 'fs-call-uuid-1234')
            ->assertJsonPath('data.recording.url', 'https://storage.netreverb.com/recordings/test.mp3')
            ->assertJsonPath('data.recording.size', 1048576)
            ->assertJsonPath('data.recording.playback_available', true);

        $this->assertDatabaseHas('call_logs', [
            'id' => $callLog->id,
            'status' => CallStatus::Completed->value,
            'duration' => 120,
            'freeswitch_uuid' => 'fs-call-uuid-1234',
            'recording_url' => 'https://storage.netreverb.com/recordings/test.mp3',
        ]);
    }

    public function test_call_with_non_completed_recording_metadata_reports_playback_as_unavailable(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        $callLog = CallLog::factory()->for($organization)->create([
            'recording_file_path' => '2026/06/30/test.wav',
            'recording_file_name' => 'test.wav',
            'recording_status' => CallRecordingStatus::Recording->value,
        ]);

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}")
            ->assertOk()
            ->assertJsonPath('data.recording.status', CallRecordingStatus::Recording->value)
            ->assertJsonPath('data.recording.playback_available', false);
    }

    public function test_completed_call_recording_is_unavailable_until_the_local_file_exists(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        $callLog = CallLog::factory()->for($organization)->create([
            'recording_file_path' => '2026/06/30/test.wav',
            'recording_file_name' => 'test.wav',
            'recording_status' => CallRecordingStatus::Completed->value,
        ]);

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}")
            ->assertOk()
            ->assertJsonPath('data.recording.playback_available', false);
    }

    public function test_owner_can_update_call_log_without_clearing_existing_freeswitch_uuid(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        $callLog = CallLog::factory()->for($organization)->create([
            'status' => CallStatus::Ringing->value,
            'freeswitch_uuid' => 'fs-call-uuid-keep-me',
        ]);

        Sanctum::actingAs($owner);

        $response = $this->putJson(
            "/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}",
            [
                'status' => CallStatus::Completed->value,
                'duration' => 32,
                'freeswitch_uuid' => null,
                'ended_at' => now()->toDateTimeString(),
            ]
        );

        $response->assertOk()
            ->assertJsonPath('data.status', CallStatus::Completed->value)
            ->assertJsonPath('data.duration', 32)
            ->assertJsonPath('data.freeswitch_uuid', 'fs-call-uuid-keep-me');

        $this->assertDatabaseHas('call_logs', [
            'id' => $callLog->id,
            'status' => CallStatus::Completed->value,
            'duration' => 32,
            'freeswitch_uuid' => 'fs-call-uuid-keep-me',
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
