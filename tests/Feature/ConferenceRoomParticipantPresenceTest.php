<?php

namespace Tests\Feature;

use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Enums\CallStatus;
use App\Enums\ConferenceParticipantStatus;
use App\Models\CallLog;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\DialableNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $gateway->shouldReceive('listMembers')->once()->with($conferenceRoom->sip_number)->andReturn([]);
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
