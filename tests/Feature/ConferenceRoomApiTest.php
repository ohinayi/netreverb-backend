<?php

namespace Tests\Feature;

use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Enums\ConferenceParticipantStatus;
use App\Enums\ConferenceRoomStatus;
use App\Enums\MembershipRole;
use App\Events\ConferenceRoomScreenShareUpdated;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\ConferenceRoomReaction;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ConferenceRooms\ConferenceRoomParticipantPresenceService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ConferenceRoomApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_create_a_conference_room_with_a_dynamic_sip_number(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms",
            [
                'title' => 'Weekly standup',
                'passcode' => '123456',
                'expires_in_minutes' => 90,
            ],
        );

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Weekly standup')
            ->assertJsonPath('data.status', ConferenceRoomStatus::Active->value)
            ->assertJsonPath('data.organization_public_id', $organization->public_id)
            ->assertJsonPath('data.invite_url', 'http://localhost:5174/app/meetings/join?invite='.$response->json('data.invite_code'))
            ->assertJsonPath('data.passcode_required', true);

        $this->assertIsString($response->json('data.invite_code'));
        $this->assertSame(22, strlen($response->json('data.invite_code')));
        $response->assertJsonMissingPath('data.sip_number')
            ->assertJsonMissingPath('data.room_id');

        $room = ConferenceRoom::query()->sole();

        $this->assertSame($organization->id, $room->organization_id);
        $this->assertSame($admin->id, $room->host_user_id);
        $this->assertSame($response->json('data.invite_code'), $room->invite_code);
        $this->assertStringStartsWith('45', $room->sip_number);
        $this->assertSame(11, strlen($room->sip_number));
        $this->assertSame(1, $room->participants()->count());

        $participant = ConferenceRoomParticipant::query()->sole();
        $this->assertSame(ConferenceParticipantStatus::Joined, $participant->status);
        $this->assertSame('host', $participant->role);
    }

    public function test_member_cannot_create_a_conference_room(): void
    {
        $member = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($member)->create([
            'role' => MembershipRole::Member,
        ]);
        Sanctum::actingAs($member);

        $this->postJson("/api/v1/organizations/{$organization->public_id}/conference-rooms", [
            'title' => 'Blocked meeting',
        ])->assertForbidden();
    }

    public function test_host_can_invite_join_and_end_the_room(): void
    {
        $host = User::factory()->create();
        $participantUser = User::factory()->create();
        $organization = Organization::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();
        OrganizationMembership::factory()->for($organization)->for($participantUser)->create([
            'role' => MembershipRole::Member,
        ]);

        Sanctum::actingAs($host);

        $createResponse = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms",
            ['title' => 'Product demo'],
        );

        $conferenceRoomPublicId = $createResponse->json('data.public_id');
        $inviteCode = $createResponse->json('data.invite_code');

        $inviteResponse = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoomPublicId}/invite",
            ['user_public_id' => $participantUser->public_id],
        );

        $inviteResponse->assertOk()
            ->assertJsonFragment(['public_id' => $participantUser->public_id]);

        Sanctum::actingAs($participantUser);

        $resolveResponse = $this->getJson("/api/v1/conference-rooms/resolve?code={$inviteCode}");

        $resolveResponse->assertOk()
            ->assertJsonPath('data.public_id', $conferenceRoomPublicId)
            ->assertJsonPath('data.organization_public_id', $organization->public_id)
            ->assertJsonPath('data.invite_code', $inviteCode)
            ->assertJsonPath('data.can_join_directly', true)
            ->assertJsonPath('data.waiting_room_required', false);

        $joinResponse = $this->postJson(
            '/api/v1/conference-rooms/join-by-invite',
            [
                'invite_code' => $inviteCode,
                'display_name' => 'Remote guest',
            ],
        );

        $joinResponse->assertOk()
            ->assertJsonPath('data.sip_number', ConferenceRoom::query()->sole()->sip_number)
            ->assertJsonPath('data.current_user_participant.status', ConferenceParticipantStatus::Joined->value)
            ->assertJsonPath('data.current_user_participant.screen_share.is_sharing', false)
            ->assertJsonPath('data.current_user_participant.screen_share.blocked_by_host', false);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoomPublicId}/join",
            [
                'invite_code' => 'A123456789012345678901',
                'display_name' => 'Wrong token',
            ],
        )->assertUnprocessable()->assertJsonValidationErrors('invite_code');

        Sanctum::actingAs($host);

        $endResponse = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoomPublicId}/end",
        );

        $endResponse->assertOk()
            ->assertJsonPath('data.status', ConferenceRoomStatus::Ended->value);

        $this->assertSame(ConferenceRoomStatus::Ended, ConferenceRoom::query()->sole()->status);
    }

    public function test_member_can_resolve_and_join_room_from_invite_without_org_scoped_url(): void
    {
        $user = User::factory()->create();
        $selectedOrganization = Organization::factory()->create();
        $roomOrganization = Organization::factory()->create();

        OrganizationMembership::factory()->for($selectedOrganization)->for($user)->create();
        OrganizationMembership::factory()->for($roomOrganization)->for($user)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($roomOrganization)->for($user, 'hostUser')->create();
        ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($user)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'role' => 'host',
            'display_name' => $user->name,
            'email' => $user->email,
            'joined_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/conference-rooms/resolve?code={$conferenceRoom->invite_code}")
            ->assertOk()
            ->assertJsonPath('data.public_id', $conferenceRoom->public_id)
            ->assertJsonPath('data.organization_public_id', $roomOrganization->public_id)
            ->assertJsonPath('data.can_join_directly', true)
            ->assertJsonPath('data.waiting_room_required', false);

        $this->postJson('/api/v1/conference-rooms/join-by-invite', [
            'invite_code' => $conferenceRoom->invite_code,
        ])->assertOk()
            ->assertJsonPath('data.sip_number', $conferenceRoom->sip_number);

        $this->postJson("/api/v1/organizations/{$roomOrganization->public_id}/conference-rooms/{$conferenceRoom->public_id}/join", [
            'invite_code' => $conferenceRoom->invite_code,
        ])->assertOk()
            ->assertJsonPath('data.sip_number', $conferenceRoom->sip_number);
    }

    public function test_member_can_leave_room_from_invite_without_org_scoped_url(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $organization = Organization::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();
        OrganizationMembership::factory()->for($organization)->for($guest)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($host, 'hostUser')->create();
        ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($host)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'role' => 'host',
            'display_name' => $host->name,
            'email' => $host->email,
            'joined_at' => now()->subMinutes(2),
        ]);
        $participant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($guest)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'role' => 'participant',
            'display_name' => $guest->name,
            'email' => $guest->email,
            'joined_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($guest);

        $this->postJson('/api/v1/conference-rooms/leave-by-invite', [
            'invite_code' => $conferenceRoom->invite_code,
        ])->assertOk()
            ->assertJsonPath('data.current_user_participant.status', ConferenceParticipantStatus::Left->value)
            ->assertJsonPath('data.public_id', $conferenceRoom->public_id);

        $participant->refresh();

        $this->assertSame(ConferenceParticipantStatus::Left, $participant->status);
        $this->assertNotNull($participant->left_at);
    }

    public function test_same_organization_member_is_placed_in_waiting_room_by_default(): void
    {
        $host = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();
        OrganizationMembership::factory()->for($organization)->for($member)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($host, 'hostUser')->create();

        Sanctum::actingAs($member);

        $this->getJson("/api/v1/conference-rooms/resolve?code={$conferenceRoom->invite_code}")
            ->assertOk()
            ->assertJsonPath('data.can_join_directly', false)
            ->assertJsonPath('data.waiting_room_required', true);

        $this->postJson("/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/join", [
            'invite_code' => $conferenceRoom->invite_code,
        ])->assertAccepted()
            ->assertJsonPath('data.current_user_participant.status', ConferenceParticipantStatus::Waiting->value)
            ->assertJsonMissingPath('data.sip_number');
    }

    public function test_open_room_allows_direct_join_without_waiting_room(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $organization = Organization::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($host, 'hostUser')->create([
            'configuration' => [
                'is_open' => true,
            ],
        ]);

        Sanctum::actingAs($guest);

        $this->getJson("/api/v1/conference-rooms/resolve?code={$conferenceRoom->invite_code}")
            ->assertOk()
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.can_join_directly', true)
            ->assertJsonPath('data.waiting_room_required', false);

        $this->postJson('/api/v1/conference-rooms/join-by-invite', [
            'invite_code' => $conferenceRoom->invite_code,
        ])->assertOk()
            ->assertJsonPath('data.sip_number', $conferenceRoom->sip_number)
            ->assertJsonPath('data.current_user_participant.status', ConferenceParticipantStatus::Joined->value);
    }

    public function test_host_never_enters_own_waiting_room_via_invite_flow(): void
    {
        $host = User::factory()->create();
        $organization = Organization::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();

        Sanctum::actingAs($host);

        $createResponse = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms",
            [
                'title' => 'Private leadership sync',
                'configuration' => [
                    'is_open' => false,
                ],
            ],
        )->assertCreated();

        $inviteCode = $createResponse->json('data.invite_code');
        $roomPublicId = $createResponse->json('data.public_id');

        $this->getJson("/api/v1/conference-rooms/resolve?code={$inviteCode}")
            ->assertOk()
            ->assertJsonPath('data.public_id', $roomPublicId)
            ->assertJsonPath('data.is_open', false)
            ->assertJsonPath('data.can_join_directly', true)
            ->assertJsonPath('data.waiting_room_required', false)
            ->assertJsonPath('data.current_user_participant.status', ConferenceParticipantStatus::Joined->value);

        $this->postJson('/api/v1/conference-rooms/join-by-invite', [
            'invite_code' => $inviteCode,
        ])->assertOk()
            ->assertJsonPath('data.public_id', $roomPublicId)
            ->assertJsonPath('data.can_join_directly', true)
            ->assertJsonPath('data.waiting_room_required', false)
            ->assertJsonPath('data.current_user_participant.status', ConferenceParticipantStatus::Joined->value)
            ->assertJsonPath('data.sip_number', ConferenceRoom::query()->where('public_id', $roomPublicId)->sole()->sip_number);
    }

    public function test_non_member_is_placed_in_waiting_room_until_admitted(): void
    {
        $host = User::factory()->create();
        $outsider = User::factory()->create();
        $coworker = User::factory()->create();
        $organization = Organization::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();
        OrganizationMembership::factory()->for($organization)->for($coworker)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($host, 'hostUser')->create();
        ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($coworker)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => $coworker->name,
            'email' => $coworker->email,
            'joined_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/conference-rooms/resolve?code={$conferenceRoom->invite_code}")
            ->assertOk()
            ->assertJsonPath('data.public_id', $conferenceRoom->public_id)
            ->assertJsonPath('data.can_join_directly', false)
            ->assertJsonPath('data.waiting_room_required', true)
            ->assertJsonPath('data.current_user_participant', null)
            ->assertJsonMissingPath('data.participants');

        $waitingResponse = $this->postJson('/api/v1/conference-rooms/join-by-invite', [
            'invite_code' => $conferenceRoom->invite_code,
            'display_name' => 'External guest',
        ]);

        $waitingResponse->assertAccepted()
            ->assertJsonPath('data.current_user_participant.status', ConferenceParticipantStatus::Waiting->value)
            ->assertJsonMissingPath('data.sip_number')
            ->assertJsonMissingPath('data.participants');

        $waitingParticipantPublicId = $waitingResponse->json('data.current_user_participant.public_id');

        Sanctum::actingAs($host);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/waiting-participants")
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $waitingParticipantPublicId)
            ->assertJsonPath('data.0.status', ConferenceParticipantStatus::Waiting->value);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$waitingParticipantPublicId}/admit",
            ['invite_code' => $conferenceRoom->invite_code],
        )->assertOk()
            ->assertJsonPath('data.status', ConferenceParticipantStatus::Invited->value);

        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/conference-rooms/resolve?code={$conferenceRoom->invite_code}")
            ->assertOk()
            ->assertJsonPath('data.current_user_participant.status', ConferenceParticipantStatus::Invited->value)
            ->assertJsonPath('data.can_join_directly', true)
            ->assertJsonPath('data.waiting_room_required', false)
            ->assertJsonCount(2, 'data.participants');

        $this->postJson('/api/v1/conference-rooms/join-by-invite', [
            'invite_code' => $conferenceRoom->invite_code,
        ])->assertOk()
            ->assertJsonPath('data.sip_number', $conferenceRoom->sip_number)
            ->assertJsonPath('data.current_user_participant.status', ConferenceParticipantStatus::Joined->value)
            ->assertJsonCount(2, 'data.participants');
    }

    public function test_host_can_deny_waiting_participant(): void
    {
        $host = User::factory()->create();
        $outsider = User::factory()->create();
        $organization = Organization::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($host, 'hostUser')->create();

        Sanctum::actingAs($outsider);

        $waitingParticipantPublicId = $this->postJson('/api/v1/conference-rooms/join-by-invite', [
            'invite_code' => $conferenceRoom->invite_code,
        ])->assertAccepted()
            ->json('data.current_user_participant.public_id');

        Sanctum::actingAs($host);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$waitingParticipantPublicId}/deny",
            ['invite_code' => $conferenceRoom->invite_code],
        )->assertOk()
            ->assertJsonPath('data.status', ConferenceParticipantStatus::Denied->value);

        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/conference-rooms/resolve?code={$conferenceRoom->invite_code}")
            ->assertOk()
            ->assertJsonPath('data.current_user_participant.status', ConferenceParticipantStatus::Denied->value)
            ->assertJsonPath('data.can_join_directly', false)
            ->assertJsonPath('data.waiting_room_required', true);
    }

    public function test_wrong_organization_room_lookup_returns_clearer_not_found_message(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($user)->create();
        OrganizationMembership::factory()->for($otherOrganization)->for($user)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($user, 'hostUser')->create();

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/organizations/{$otherOrganization->public_id}/conference-rooms/{$conferenceRoom->public_id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Conference room not found for this organization.');
    }

    public function test_expired_rooms_are_marked_inactive_by_the_scheduler_command(): void
    {
        $host = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($host, 'hostUser')->create([
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('conference-rooms:expire')
            ->assertExitCode(0);

        $conferenceRoom->refresh();

        $this->assertSame(ConferenceRoomStatus::Expired, $conferenceRoom->status);
    }

    public function test_expired_room_returns_clear_warning_for_invite_resolution_and_join(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $organization = Organization::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($host, 'hostUser')->create([
            'expires_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($guest);

        $this->getJson("/api/v1/conference-rooms/resolve?code={$conferenceRoom->invite_code}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conference_room')
            ->assertJsonPath('errors.conference_room.0', 'This meeting invite has expired.');

        $this->postJson('/api/v1/conference-rooms/join-by-invite', [
            'invite_code' => $conferenceRoom->invite_code,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('conference_room')
            ->assertJsonPath('errors.conference_room.0', 'This meeting invite has expired.');

        $conferenceRoom->refresh();

        $this->assertSame(ConferenceRoomStatus::Expired, $conferenceRoom->status);
    }

    public function test_admin_can_create_a_conference_room_with_an_explicit_schedule_window(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        Sanctum::actingAs($admin);

        $startsAt = now()->addDay()->setTime(9, 30);
        $expiresAt = $startsAt->copy()->addHours(3);

        $response = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms",
            [
                'title' => 'Quarterly planning',
                'starts_at' => $startsAt->toISOString(),
                'expires_at' => $expiresAt->toISOString(),
            ],
        );

        $response->assertCreated();

        $conferenceRoom = ConferenceRoom::query()->sole();

        $this->assertTrue($conferenceRoom->starts_at->equalTo($startsAt));
        $this->assertTrue($conferenceRoom->expires_at->equalTo($expiresAt));
    }

    public function test_host_can_block_and_restore_screen_sharing_for_a_participant(): void
    {
        $host = User::factory()->create();
        $participantUser = User::factory()->create();
        $organization = Organization::factory()->create();

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
            'metadata' => [
                'screen_share' => [
                    'active' => true,
                    'started_at' => now()->subMinutes(2)->toIso8601String(),
                ],
            ],
        ]);
        $this->touchPresenceHeartbeat($hostParticipant);
        $this->touchPresenceHeartbeat($participant);
        $this->fakeConferenceControlForParticipants($conferenceRoom, 2, $participant);

        Event::fake([ConferenceRoomScreenShareUpdated::class]);
        Sanctum::actingAs($host);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$participant->public_id}/screen-share-off",
        )->assertOk()
            ->assertJsonPath('data.screen_share.is_sharing', false)
            ->assertJsonPath('data.screen_share.blocked_by_host', true);

        Event::assertDispatched(ConferenceRoomScreenShareUpdated::class, function (ConferenceRoomScreenShareUpdated $event) use ($conferenceRoom, $participant): bool {
            return $event->conferenceRoom->is($conferenceRoom)
                && $event->participant->is($participant)
                && $event->action === 'blocked'
                && $event->source === 'screen';
        });

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$participant->public_id}/screen-share-on",
        )->assertOk()
            ->assertJsonPath('data.screen_share.blocked_by_host', false);

        Event::assertDispatched(ConferenceRoomScreenShareUpdated::class, function (ConferenceRoomScreenShareUpdated $event) use ($conferenceRoom, $participant): bool {
            return $event->conferenceRoom->is($conferenceRoom)
                && $event->participant->is($participant)
                && $event->action === 'unblocked'
                && $event->source === 'screen';
        });
    }

    public function test_blocked_participant_cannot_start_screen_sharing(): void
    {
        $host = User::factory()->create();
        $participantUser = User::factory()->create();
        $organization = Organization::factory()->create();

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
            'metadata' => [
                'screen_share' => [
                    'blocked_by_host' => true,
                ],
            ],
        ]);
        $this->touchPresenceHeartbeat($hostParticipant);
        $this->touchPresenceHeartbeat($participant);
        $this->fakeConferenceControlForParticipants($conferenceRoom, 1, $participant);

        Sanctum::actingAs($participantUser);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$participant->public_id}/screen-share-start",
        )->assertUnprocessable()
            ->assertJsonValidationErrors('participant');
    }

    public function test_participant_can_start_and_stop_screen_sharing_when_not_blocked(): void
    {
        $host = User::factory()->create();
        $participantUser = User::factory()->create();
        $organization = Organization::factory()->create();

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
        $this->touchPresenceHeartbeat($hostParticipant);
        $this->touchPresenceHeartbeat($participant);
        $this->fakeConferenceControlForParticipants($conferenceRoom, 2, $participant);

        Event::fake([ConferenceRoomScreenShareUpdated::class]);
        Sanctum::actingAs($participantUser);

        $startResponse = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$participant->public_id}/screen-share-start",
        );

        $startResponse->assertOk()
            ->assertJsonPath('data.screen_share.is_sharing', true)
            ->assertJsonPath('data.screen_share.blocked_by_host', false);

        Event::assertDispatched(ConferenceRoomScreenShareUpdated::class, function (ConferenceRoomScreenShareUpdated $event) use ($conferenceRoom, $participant): bool {
            return $event->conferenceRoom->is($conferenceRoom)
                && $event->participant->is($participant)
                && $event->action === 'started'
                && $event->source === 'screen';
        });

        $participant->refresh();

        $this->assertTrue((bool) data_get($participant->metadata, 'screen_share.is_sharing'));

        $stopResponse = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$participant->public_id}/screen-share-stop",
        );

        $stopResponse->assertOk()
            ->assertJsonPath('data.screen_share.is_sharing', false);

        Event::assertDispatched(ConferenceRoomScreenShareUpdated::class, function (ConferenceRoomScreenShareUpdated $event) use ($conferenceRoom, $participant): bool {
            return $event->conferenceRoom->is($conferenceRoom)
                && $event->participant->is($participant)
                && $event->action === 'stopped'
                && $event->source === 'screen';
        });
    }

    public function test_participant_cannot_start_screen_sharing_when_another_participant_is_already_sharing(): void
    {
        $host = User::factory()->create();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $organization = Organization::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();
        OrganizationMembership::factory()->for($organization)->for($firstUser)->create();
        OrganizationMembership::factory()->for($organization)->for($secondUser)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($host, 'hostUser')->create();
        $hostParticipant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($host)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => $host->name,
            'email' => $host->email,
            'joined_at' => now()->subMinutes(2),
        ]);
        $firstParticipant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($firstUser)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => 'First Presenter',
            'email' => $firstUser->email,
            'joined_at' => now()->subMinute(),
            'metadata' => [
                'screen_share' => [
                    'is_sharing' => true,
                    'active' => true,
                    'started_at' => now()->subMinute()->toIso8601String(),
                ],
            ],
        ]);
        $secondParticipant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($secondUser)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'display_name' => 'Second Presenter',
            'email' => $secondUser->email,
            'joined_at' => now()->subMinute(),
        ]);
        $this->touchPresenceHeartbeat($hostParticipant);
        $this->touchPresenceHeartbeat($firstParticipant);
        $this->touchPresenceHeartbeat($secondParticipant);
        $this->fakeConferenceControlForParticipants($conferenceRoom, 2, $firstParticipant, $secondParticipant);

        Sanctum::actingAs($firstUser);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$firstParticipant->public_id}/screen-share-start",
        )->assertOk();

        Sanctum::actingAs($secondUser);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/participants/{$secondParticipant->public_id}/screen-share-start",
        )->assertStatus(409)
            ->assertJsonPath('message', 'Another participant is already sharing their screen.')
            ->assertJsonPath('error_code', 'screen_share_already_active');
    }

    public function test_participant_can_raise_hand_and_send_emoji_reaction(): void
    {
        $host = User::factory()->create();
        $participantUser = User::factory()->create();
        $organization = Organization::factory()->create();

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
        $this->touchPresenceHeartbeat($hostParticipant);
        $this->touchPresenceHeartbeat($participant);
        Sanctum::actingAs($participantUser);

        $handRaiseResponse = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/reactions",
            [
                'reaction_type' => 'raise_hand',
            ],
        );

        $handRaiseResponse->assertOk()
            ->assertJsonPath('data.current_user_participant.hand_raised', true);

        $participant->refresh();

        $this->assertTrue((bool) data_get($participant->metadata, 'reactions.hand.raised'));

        $emojiResponse = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/reactions",
            [
                'reaction_type' => 'party_popper',
                'expires_in_seconds' => 45,
            ],
        );

        $emojiResponse->assertOk()
            ->assertJsonCount(1, 'data.reactions')
            ->assertJsonPath('data.reactions.0.reaction_type', 'party_popper');

        $this->assertSame(1, ConferenceRoomReaction::query()->count());
    }

    private function touchPresenceHeartbeat(ConferenceRoomParticipant $participant): void
    {
        app(ConferenceRoomParticipantPresenceService::class)->touchHeartbeat($participant, now());
    }

    /**
     * @param  array<int, ConferenceRoomParticipant>  $participants
     */
    private function fakeConferenceControlForParticipants(
        ConferenceRoom $conferenceRoom,
        int $listMembersCalls,
        ConferenceRoomParticipant ...$participants,
    ): void {
        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);

        $gateway->shouldReceive('listMembers')
            ->times($listMembersCalls)
            ->with($conferenceRoom->sip_number)
            ->andReturn($this->conferenceMembersForParticipants(...$participants));

        $gateway->shouldReceive('kickMember')->zeroOrMoreTimes();
        $gateway->shouldReceive('muteMember')->zeroOrMoreTimes();
        $gateway->shouldReceive('unmuteMember')->zeroOrMoreTimes();
        $gateway->shouldReceive('videoMuteMember')->zeroOrMoreTimes();
        $gateway->shouldReceive('videoUnmuteMember')->zeroOrMoreTimes();
        $gateway->shouldReceive('startRecording')->zeroOrMoreTimes();
        $gateway->shouldReceive('stopRecording')->zeroOrMoreTimes();

        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);
    }

    /**
     * @return array<int, array{member_id: string, caller_number: null, caller_name: string, uuid: null}>
     */
    private function conferenceMembersForParticipants(ConferenceRoomParticipant ...$participants): array
    {
        return array_map(
            static function (ConferenceRoomParticipant $participant, int $index): array {
                return [
                    'member_id' => (string) ($index + 1),
                    'caller_number' => null,
                    'caller_name' => (string) $participant->display_name,
                    'uuid' => null,
                ];
            },
            $participants,
            array_keys($participants),
        );
    }
}
