<?php

namespace Tests\Feature;

use App\Enums\ConferenceParticipantStatus;
use App\Enums\ConferenceRoomStatus;
use App\Enums\MembershipRole;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
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
            ->assertJsonPath('data.current_user_participant.status', ConferenceParticipantStatus::Joined->value);

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
}
