<?php

namespace Tests\Feature;

use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Enums\CallStatus;
use App\Enums\ConferenceParticipantStatus;
use App\Events\ConferenceRoomParticipantPresenceUpdated;
use App\Models\CallLog;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\DialableNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ConferenceRooms\ConferenceRoomParticipantPresenceService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ConferenceRoomParticipantPresenceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reconcile_command_marks_stale_joined_participant_as_left_when_not_live(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($user)->create();

        $dialableNumber = DialableNumber::factory()->create([
            'organization_id' => $organization->id,
            'number' => '302984',
        ]);

        Extension::factory()->for($organization)->for($user)->for($dialableNumber)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($user, 'hostUser')->create();
        $participant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($user)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'joined_at' => now()->subMinutes(5),
            'left_at' => null,
        ]);

        config()->set('telephony.conference_participants.missed_reconciliations_before_leave', 2);

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $gateway->shouldReceive('listMembers')->twice()->with($conferenceRoom->sip_number)->andReturn([]);
        $gateway->shouldReceive('kickMember')->never();
        $gateway->shouldReceive('stopRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('startRecording')->zeroOrMoreTimes();
        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);

        $this->artisan('conference-rooms:reconcile-participants')
            ->assertExitCode(0);

        $participant->refresh();

        $this->assertSame(ConferenceParticipantStatus::Joined, $participant->status);
        $this->assertNull($participant->left_at);
        $this->assertSame(1, data_get($participant->metadata, 'presence_reconcile.miss_count'));
        $this->assertNotNull(data_get($participant->metadata, 'presence_reconcile.last_missing_at'));

        $this->artisan('conference-rooms:reconcile-participants')
            ->assertExitCode(0);

        $participant->refresh();

        $this->assertSame(ConferenceParticipantStatus::Left, $participant->status);
        $this->assertNotNull($participant->left_at);
    }

    public function test_reconcile_command_keeps_joined_participant_when_live_member_matches_active_call_log_uuid(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($user)->create();

        $dialableNumber = DialableNumber::factory()->create([
            'organization_id' => $organization->id,
            'number' => '302989',
        ]);

        Extension::factory()->for($organization)->for($user)->for($dialableNumber)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($user, 'hostUser')->create([
            'sip_number' => '45000000013',
        ]);
        $participant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($user)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => $user->name,
            'email' => $user->email,
            'joined_at' => now()->subMinutes(5),
            'left_at' => null,
        ]);

        CallLog::factory()->for($organization)->create([
            'caller_number' => '302989',
            'callee_number' => '45000000013',
            'status' => CallStatus::InProgress,
            'freeswitch_uuid' => 'fs-live-member-uuid',
        ]);

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $gateway->shouldReceive('listMembers')->once()->with($conferenceRoom->sip_number)->andReturn([
            [
                'member_id' => '13',
                'caller_number' => null,
                'caller_name' => 'Phone participant',
                'uuid' => 'fs-live-member-uuid',
            ],
        ]);
        $gateway->shouldReceive('kickMember')->never();
        $gateway->shouldReceive('stopRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('startRecording')->zeroOrMoreTimes();
        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);

        $this->artisan('conference-rooms:reconcile-participants')
            ->assertExitCode(0);

        $participant->refresh();

        $this->assertSame(ConferenceParticipantStatus::Joined, $participant->status);
        $this->assertNull($participant->left_at);
    }

    public function test_reconcile_command_clears_transient_miss_state_once_participant_is_seen_again(): void
    {
        config()->set('telephony.conference_participants.missed_reconciliations_before_leave', 2);

        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($user)->create();

        $dialableNumber = DialableNumber::factory()->create([
            'organization_id' => $organization->id,
            'number' => '302990',
        ]);

        Extension::factory()->for($organization)->for($user)->for($dialableNumber)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($user, 'hostUser')->create([
            'sip_number' => '45000000014',
        ]);
        $participant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($user)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => $user->name,
            'email' => $user->email,
            'joined_at' => now()->subMinutes(5),
            'left_at' => null,
            'metadata' => [
                'presence_reconcile' => [
                    'miss_count' => 1,
                    'last_missing_at' => now()->subSeconds(30)->toIso8601String(),
                ],
            ],
        ]);

        CallLog::factory()->for($organization)->create([
            'caller_number' => '302990',
            'callee_number' => '45000000014',
            'status' => CallStatus::InProgress,
            'freeswitch_uuid' => 'fs-live-member-uuid-reset',
        ]);

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $gateway->shouldReceive('listMembers')->once()->with($conferenceRoom->sip_number)->andReturn([
            [
                'member_id' => '14',
                'caller_number' => null,
                'caller_name' => 'Phone participant',
                'uuid' => 'fs-live-member-uuid-reset',
            ],
        ]);
        $gateway->shouldReceive('kickMember')->never();
        $gateway->shouldReceive('stopRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('startRecording')->zeroOrMoreTimes();
        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);

        $this->artisan('conference-rooms:reconcile-participants')
            ->assertExitCode(0);

        $participant->refresh();

        $this->assertSame(ConferenceParticipantStatus::Joined, $participant->status);
        $this->assertNull($participant->left_at);
        $this->assertSame(0, data_get($participant->metadata, 'presence_reconcile.miss_count'));
        $this->assertNull(data_get($participant->metadata, 'presence_reconcile.last_missing_at'));
    }

    public function test_reconcile_command_marks_participant_left_when_remaining_member_only_matches_by_shared_number_but_name_differs(): void
    {
        config()->set('telephony.conference_participants.missed_reconciliations_before_leave', 1);

        $host = User::factory()->create(['name' => 'Host User']);
        $guest = User::factory()->create(['name' => 'Guest User']);
        $organization = Organization::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();
        OrganizationMembership::factory()->for($organization)->for($guest)->create();

        $sharedDialableNumber = DialableNumber::factory()->create([
            'organization_id' => $organization->id,
            'number' => '100000',
        ]);

        Extension::factory()->for($organization)->for($guest)->for($sharedDialableNumber)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($host, 'hostUser')->create();
        $participant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($guest)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => $guest->name,
            'email' => $guest->email,
            'joined_at' => now()->subMinutes(5),
            'left_at' => null,
        ]);

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $gateway->shouldReceive('listMembers')->once()->with($conferenceRoom->sip_number)->andReturn([
            [
                'member_id' => '18',
                'caller_number' => '100000',
                'caller_name' => $host->name,
                'uuid' => 'host-still-live',
            ],
        ]);
        $gateway->shouldReceive('kickMember')->never();
        $gateway->shouldReceive('stopRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('startRecording')->zeroOrMoreTimes();
        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);

        $this->artisan('conference-rooms:reconcile-participants')
            ->assertExitCode(0);

        $participant->refresh();

        $this->assertSame(ConferenceParticipantStatus::Left, $participant->status);
        $this->assertNotNull($participant->left_at);
    }

    public function test_host_can_remove_participant(): void
    {
        $host = User::factory()->create();
        $participantUser = User::factory()->create();
        $organization = Organization::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();
        OrganizationMembership::factory()->for($organization)->for($participantUser)->create();

        $dialableNumber = DialableNumber::factory()->create([
            'organization_id' => $organization->id,
            'number' => '302985',
        ]);

        Extension::factory()->for($organization)->for($participantUser)->for($dialableNumber)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($host, 'hostUser')->create();
        $participant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($participantUser)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => $participantUser->name,
            'email' => $participantUser->email,
            'joined_at' => now()->subMinute(),
        ]);

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $gateway->shouldReceive('listMembers')->once()->with($conferenceRoom->sip_number)->andReturn([
            [
                'member_id' => '7',
                'caller_number' => '302985',
                'caller_name' => $participantUser->name,
            ],
        ]);
        $gateway->shouldReceive('kickMember')->once()->with($conferenceRoom->sip_number, '7');
        $gateway->shouldReceive('stopRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('startRecording')->zeroOrMoreTimes();
        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);

        Sanctum::actingAs($host);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$participant->public_id}/remove",
        )->assertOk()
            ->assertJsonPath('data.status', ConferenceParticipantStatus::Removed->value);

        $participant->refresh();

        $this->assertSame(ConferenceParticipantStatus::Removed, $participant->status);
        $this->assertNotNull($participant->left_at);
    }

    public function test_leave_kicks_all_matching_live_members_and_marks_participant_left(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        OrganizationMembership::factory()->for($organization)->for($user)->create();

        $dialableNumber = DialableNumber::factory()->create([
            'organization_id' => $organization->id,
            'number' => '302988',
        ]);

        Extension::factory()->for($organization)->for($user)->for($dialableNumber)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($user, 'hostUser')->create();
        $participant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($user)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => $user->name,
            'email' => $user->email,
            'joined_at' => now()->subMinute(),
        ]);

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $gateway->shouldReceive('listMembers')->once()->with($conferenceRoom->sip_number)->andReturn([
            [
                'member_id' => '10',
                'caller_number' => '302988',
                'caller_name' => $user->name,
            ],
            [
                'member_id' => '11',
                'caller_number' => '302988',
                'caller_name' => $user->name,
            ],
        ]);
        $gateway->shouldReceive('kickMember')->once()->with($conferenceRoom->sip_number, '10');
        $gateway->shouldReceive('kickMember')->once()->with($conferenceRoom->sip_number, '11');
        $gateway->shouldReceive('stopRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('startRecording')->zeroOrMoreTimes();
        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);

        Sanctum::actingAs($user);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/leave",
        )->assertOk()
            ->assertJsonPath('data.status', 'active');

        $participant->refresh();

        $this->assertSame(ConferenceParticipantStatus::Left, $participant->status);
        $this->assertNotNull($participant->left_at);
    }

    public function test_leave_marks_participant_left_even_when_no_live_member_is_found(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        OrganizationMembership::factory()->for($organization)->for($user)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($user, 'hostUser')->create();
        $participant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($user)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => $user->name,
            'email' => $user->email,
            'joined_at' => now()->subMinute(),
        ]);

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $gateway->shouldReceive('listMembers')->once()->with($conferenceRoom->sip_number)->andReturn([]);
        $gateway->shouldReceive('kickMember')->never();
        $gateway->shouldReceive('stopRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('startRecording')->zeroOrMoreTimes();
        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);

        Sanctum::actingAs($user);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/leave",
        )->assertOk();

        $participant->refresh();

        $this->assertSame(ConferenceParticipantStatus::Left, $participant->status);
        $this->assertNotNull($participant->left_at);
    }

    public function test_host_can_mute_and_unmute_participant_audio(): void
    {
        [$organization, $conferenceRoom, $host, $participant] = $this->prepareModeratedParticipant();

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $gateway->shouldReceive('listMembers')->twice()->with($conferenceRoom->sip_number)->andReturn([
            [
                'member_id' => '8',
                'caller_number' => '302986',
                'caller_name' => $participant->display_name,
            ],
        ]);
        $gateway->shouldReceive('muteMember')->once()->with($conferenceRoom->sip_number, '8');
        $gateway->shouldReceive('unmuteMember')->once()->with($conferenceRoom->sip_number, '8');
        $gateway->shouldReceive('kickMember')->never();
        $gateway->shouldReceive('stopRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('startRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('videoMuteMember')->never();
        $gateway->shouldReceive('videoUnmuteMember')->never();
        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);

        Sanctum::actingAs($host);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$participant->public_id}/mute",
        )->assertOk()
            ->assertJsonPath('data.moderation.audio_muted_by_host', true)
            ->assertJsonPath('data.moderation.video_muted_by_host', false);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$participant->public_id}/unmute",
        )->assertOk()
            ->assertJsonPath('data.moderation.audio_muted_by_host', false);
    }

    public function test_host_can_mute_participant_when_live_member_matches_active_call_log_uuid(): void
    {
        [$organization, $conferenceRoom, $host, $participant] = $this->prepareModeratedParticipant('302990');

        CallLog::factory()->for($organization)->create([
            'caller_number' => '302990',
            'callee_number' => $conferenceRoom->sip_number,
            'status' => CallStatus::InProgress,
            'freeswitch_uuid' => 'fs-uuid-mute-match',
        ]);

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $gateway->shouldReceive('listMembers')->once()->with($conferenceRoom->sip_number)->andReturn([
            [
                'member_id' => '14',
                'caller_number' => null,
                'caller_name' => 'Phone participant',
                'uuid' => 'fs-uuid-mute-match',
            ],
        ]);
        $gateway->shouldReceive('muteMember')->once()->with($conferenceRoom->sip_number, '14');
        $gateway->shouldReceive('kickMember')->never();
        $gateway->shouldReceive('stopRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('startRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('unmuteMember')->never();
        $gateway->shouldReceive('videoMuteMember')->never();
        $gateway->shouldReceive('videoUnmuteMember')->never();
        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);

        Sanctum::actingAs($host);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$participant->public_id}/mute",
        )->assertOk()
            ->assertJsonPath('data.moderation.audio_muted_by_host', true);
    }

    public function test_host_can_turn_participant_video_off_and_on(): void
    {
        [$organization, $conferenceRoom, $host, $participant] = $this->prepareModeratedParticipant('302987');

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $gateway->shouldReceive('listMembers')->twice()->with($conferenceRoom->sip_number)->andReturn([
            [
                'member_id' => '9',
                'caller_number' => '302987',
                'caller_name' => $participant->display_name,
            ],
        ]);
        $gateway->shouldReceive('videoMuteMember')->once()->with($conferenceRoom->sip_number, '9');
        $gateway->shouldReceive('videoUnmuteMember')->once()->with($conferenceRoom->sip_number, '9');
        $gateway->shouldReceive('kickMember')->never();
        $gateway->shouldReceive('stopRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('startRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('muteMember')->never();
        $gateway->shouldReceive('unmuteMember')->never();
        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);

        Sanctum::actingAs($host);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$participant->public_id}/video-off",
        )->assertOk()
            ->assertJsonPath('data.moderation.video_muted_by_host', true);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$participant->public_id}/video-on",
        )->assertOk()
            ->assertJsonPath('data.moderation.video_muted_by_host', false);
    }

    public function test_host_gets_clean_error_when_conference_control_is_unavailable_for_mute(): void
    {
        [$organization, $conferenceRoom, $host, $participant] = $this->prepareModeratedParticipant();

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $gateway->shouldReceive('listMembers')
            ->once()
            ->with($conferenceRoom->sip_number)
            ->andThrow(new \RuntimeException('Unable to connect to the FreeSWITCH event socket at 127.0.0.1:8021 (Connection refused).'));
        $gateway->shouldReceive('kickMember')->never();
        $gateway->shouldReceive('stopRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('startRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('muteMember')->never();
        $gateway->shouldReceive('unmuteMember')->never();
        $gateway->shouldReceive('videoMuteMember')->never();
        $gateway->shouldReceive('videoUnmuteMember')->never();
        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);

        Sanctum::actingAs($host);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$participant->public_id}/mute",
        )->assertStatus(503)
            ->assertJson([
                'message' => 'Conference control unavailable.',
                'error_code' => 'conference_control_unavailable',
            ])
            ->assertJsonPath(
                'details',
                'FreeSWITCH event socket is not connected. Start the configured tunnel or restore backend access to FreeSWITCH and try again.',
            );
    }

    public function test_mute_does_not_mark_participant_left_when_live_member_is_missing(): void
    {
        [$organization, $conferenceRoom, $host, $participant] = $this->prepareModeratedParticipant();

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $gateway->shouldReceive('listMembers')->once()->with($conferenceRoom->sip_number)->andReturn([]);
        $gateway->shouldReceive('kickMember')->never();
        $gateway->shouldReceive('stopRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('startRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('muteMember')->never();
        $gateway->shouldReceive('unmuteMember')->never();
        $gateway->shouldReceive('videoMuteMember')->never();
        $gateway->shouldReceive('videoUnmuteMember')->never();
        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);

        Sanctum::actingAs($host);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$participant->public_id}/mute",
        )->assertUnprocessable()
            ->assertJsonValidationErrors('participant');

        $participant->refresh();

        $this->assertSame(ConferenceParticipantStatus::Joined, $participant->status);
        $this->assertNull($participant->left_at);
    }

    public function test_mute_does_not_mark_participant_left_when_live_room_members_exist_but_matching_fails(): void
    {
        [$organization, $conferenceRoom, $host, $participant] = $this->prepareModeratedParticipant();

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $gateway->shouldReceive('listMembers')->once()->with($conferenceRoom->sip_number)->andReturn([
            [
                'member_id' => '12',
                'caller_number' => '555555',
                'caller_name' => 'Phone participant',
            ],
        ]);
        $gateway->shouldReceive('kickMember')->never();
        $gateway->shouldReceive('stopRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('startRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('muteMember')->never();
        $gateway->shouldReceive('unmuteMember')->never();
        $gateway->shouldReceive('videoMuteMember')->never();
        $gateway->shouldReceive('videoUnmuteMember')->never();
        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);

        Sanctum::actingAs($host);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$participant->public_id}/mute",
        )->assertUnprocessable()
            ->assertJsonValidationErrors('participant');

        $participant->refresh();

        $this->assertSame(ConferenceParticipantStatus::Joined, $participant->status);
        $this->assertNull($participant->left_at);
    }

    public function test_mute_returns_conference_control_unavailable_when_live_member_has_no_usable_member_id(): void
    {
        [$organization, $conferenceRoom, $host, $participant] = $this->prepareModeratedParticipant();

        CallLog::factory()->for($organization)->create([
            'caller_number' => '302986',
            'callee_number' => $conferenceRoom->sip_number,
            'status' => CallStatus::InProgress,
            'freeswitch_uuid' => 'fs-channel-only-member',
        ]);

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $gateway->shouldReceive('listMembers')->once()->with($conferenceRoom->sip_number)->andReturn([
            [
                'member_id' => '',
                'caller_number' => null,
                'caller_name' => 'Phone participant',
                'uuid' => 'fs-channel-only-member',
            ],
        ]);
        $gateway->shouldReceive('kickMember')->never();
        $gateway->shouldReceive('stopRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('startRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('muteMember')->never();
        $gateway->shouldReceive('unmuteMember')->never();
        $gateway->shouldReceive('videoMuteMember')->never();
        $gateway->shouldReceive('videoUnmuteMember')->never();
        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);

        Sanctum::actingAs($host);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$participant->public_id}/mute",
        )->assertStatus(503)
            ->assertJson([
                'message' => 'Conference control unavailable.',
                'error_code' => 'conference_control_unavailable',
            ])
            ->assertJsonPath(
                'details',
                'FreeSWITCH did not return a usable conference member roster for this room. The participant may still be connected; retry once the conference roster is available.',
            );

        $participant->refresh();

        $this->assertSame(ConferenceParticipantStatus::Joined, $participant->status);
        $this->assertNull($participant->left_at);
    }

    public function test_host_gets_clean_error_when_conference_control_is_unavailable_for_remove(): void
    {
        [$organization, $conferenceRoom, $host, $participant] = $this->prepareModeratedParticipant();

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $gateway->shouldReceive('listMembers')
            ->once()
            ->with($conferenceRoom->sip_number)
            ->andThrow(new \RuntimeException('Unable to connect to the FreeSWITCH event socket at 127.0.0.1:8021 (Connection refused).'));
        $gateway->shouldReceive('kickMember')->never();
        $gateway->shouldReceive('stopRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('startRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('muteMember')->never();
        $gateway->shouldReceive('unmuteMember')->never();
        $gateway->shouldReceive('videoMuteMember')->never();
        $gateway->shouldReceive('videoUnmuteMember')->never();
        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);

        Sanctum::actingAs($host);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$participant->public_id}/remove",
        )->assertStatus(503)
            ->assertJson([
                'message' => 'Conference control unavailable.',
                'error_code' => 'conference_control_unavailable',
            ]);
    }

    public function test_participant_heartbeat_endpoint_refreshes_in_memory_presence(): void
    {
        $organization = Organization::factory()->create();
        $host = User::factory()->create();
        $participantUser = User::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();
        OrganizationMembership::factory()->for($organization)->for($participantUser)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($host, 'hostUser')->create();
        ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($host)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => $host->name,
            'email' => $host->email,
            'joined_at' => now()->subMinutes(2),
        ]);
        $participant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($participantUser)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => $participantUser->name,
            'email' => $participantUser->email,
            'joined_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($participantUser);

        $this->postJson("/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/presence/heartbeat")
            ->assertOk()
            ->assertJsonPath('data.public_id', $participant->public_id)
            ->assertJsonPath('data.presence.is_alive', true)
            ->assertJsonPath('data.presence.is_stale', false)
            ->assertJsonPath('data.presence.heartbeat_interval_seconds', 15)
            ->assertJsonPath('data.presence.timeout_seconds', 40);
    }

    public function test_disconnect_endpoint_marks_participant_disconnected_and_broadcasts_state_change(): void
    {
        $organization = Organization::factory()->create();
        $host = User::factory()->create();
        $participantUser = User::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();
        OrganizationMembership::factory()->for($organization)->for($participantUser)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($host, 'hostUser')->create();
        ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($host)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => $host->name,
            'email' => $host->email,
            'joined_at' => now()->subMinutes(2),
        ]);
        $participant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($participantUser)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => $participantUser->name,
            'email' => $participantUser->email,
            'joined_at' => now()->subMinute(),
        ]);

        Event::fake([ConferenceRoomParticipantPresenceUpdated::class]);
        Sanctum::actingAs($participantUser);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/presence/disconnect",
            ['reason' => 'network_loss'],
        )->assertOk()
            ->assertJsonPath('data.current_user_participant.status', ConferenceParticipantStatus::Disconnected->value)
            ->assertJsonPath('data.current_user_participant.presence.is_alive', false);

        Event::assertDispatched(ConferenceRoomParticipantPresenceUpdated::class, function (ConferenceRoomParticipantPresenceUpdated $event) use ($conferenceRoom, $participant): bool {
            return $event->payload['conference_room_public_id'] === $conferenceRoom->public_id
                && $event->payload['participant_public_id'] === $participant->public_id
                && $event->payload['status'] === ConferenceParticipantStatus::Disconnected->value;
        });

        $participant->refresh();

        $this->assertSame(ConferenceParticipantStatus::Disconnected, $participant->status);
        $this->assertNotNull($participant->left_at);
    }

    public function test_room_show_does_not_inline_reconcile_stale_heartbeats(): void
    {
        // Presence reconciliation now runs exclusively on the scheduled
        // `conference-rooms:reconcile-presence` command (see
        // test_scheduled_reconciliation_disconnects_stale_participant_after_grace_period
        // below), not inline on every room-state request. Running it inline on every
        // 2.5s poll from every connected client caused row-lock contention with
        // screen-share updates and made the whole roster flicker/disappear.
        $organization = Organization::factory()->create();
        $host = User::factory()->create();
        $participantUser = User::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();
        OrganizationMembership::factory()->for($organization)->for($participantUser)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($host, 'hostUser')->create();
        $hostParticipant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($host)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => $host->name,
            'email' => $host->email,
            'joined_at' => now()->subMinutes(2),
        ]);
        $participant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($participantUser)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => $participantUser->name,
            'email' => $participantUser->email,
            'joined_at' => now()->subMinute(),
        ]);

        $presenceService = app(ConferenceRoomParticipantPresenceService::class);
        $presenceService->touchHeartbeat($hostParticipant, now());
        $presenceService->touchHeartbeat($participant, now()->subMinutes(2));

        Sanctum::actingAs($host);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}")
            ->assertOk()
            ->assertJsonPath('data.presence.heartbeat_interval_seconds', 15)
            ->assertJsonPath('data.presence.timeout_seconds', 40)
            ->assertJsonPath('data.presence.heartbeat_url', route('organizations.conference-rooms.presence.heartbeat', [$organization, $conferenceRoom]))
            ->assertJsonPath('data.presence.disconnect_url', route('organizations.conference-rooms.presence.disconnect', [$organization, $conferenceRoom]))
            ->assertJsonFragment([
                'public_id' => $hostParticipant->public_id,
                'status' => ConferenceParticipantStatus::Joined->value,
            ])
            ->assertJsonFragment([
                'public_id' => $participant->public_id,
                'status' => ConferenceParticipantStatus::Joined->value,
            ]);

        $this->assertSame(ConferenceParticipantStatus::Joined, $participant->fresh()->status);
    }

    public function test_scheduled_reconciliation_disconnects_stale_participant_after_grace_period(): void
    {
        $organization = Organization::factory()->create();
        $host = User::factory()->create();
        $participantUser = User::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();
        OrganizationMembership::factory()->for($organization)->for($participantUser)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($host, 'hostUser')->create();
        $participant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($participantUser)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => $participantUser->name,
            'email' => $participantUser->email,
            'joined_at' => now()->subMinutes(5),
        ]);

        $presenceService = app(ConferenceRoomParticipantPresenceService::class);
        $presenceService->touchHeartbeat($participant, now()->subMinutes(5));

        // First scheduled pass: stale, but within the grace window - stays Joined.
        $this->artisan('conference-rooms:reconcile-presence')->assertExitCode(0);
        $this->assertSame(ConferenceParticipantStatus::Joined, $participant->fresh()->status);

        // Second consecutive stale pass crosses the default grace threshold (2) - Disconnected.
        $this->artisan('conference-rooms:reconcile-presence')->assertExitCode(0);
        $this->assertSame(ConferenceParticipantStatus::Disconnected, $participant->fresh()->status);
    }

    /**
     * @return array{Organization, ConferenceRoom, User, ConferenceRoomParticipant}
     */
    private function prepareModeratedParticipant(string $extensionNumber = '302986'): array
    {
        $host = User::factory()->create();
        $participantUser = User::factory()->create();
        $organization = Organization::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();
        OrganizationMembership::factory()->for($organization)->for($participantUser)->create();

        $dialableNumber = DialableNumber::factory()->create([
            'organization_id' => $organization->id,
            'number' => $extensionNumber,
        ]);

        Extension::factory()->for($organization)->for($participantUser)->for($dialableNumber)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($host, 'hostUser')->create();
        $participant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($participantUser)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => $participantUser->name,
            'email' => $participantUser->email,
            'joined_at' => now()->subMinute(),
        ]);

        return [$organization, $conferenceRoom, $host, $participant];
    }
}
