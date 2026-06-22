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
            ->assertJsonPath('data.passcode_required', true);

        $room = ConferenceRoom::query()->sole();

        $this->assertSame($organization->id, $room->organization_id);
        $this->assertSame($admin->id, $room->host_user_id);
        $this->assertStringStartsWith('45', $room->sip_number);
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

        $inviteResponse = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoomPublicId}/invite",
            ['user_public_id' => $participantUser->public_id],
        );

        $inviteResponse->assertOk()
            ->assertJsonFragment(['public_id' => $participantUser->public_id]);

        Sanctum::actingAs($participantUser);

        $joinResponse = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoomPublicId}/join",
            ['display_name' => 'Remote guest'],
        );

        $joinResponse->assertOk();

        $joinedParticipant = collect($joinResponse->json('data.participants'))
            ->firstWhere('user.public_id', $participantUser->public_id);

        $this->assertNotNull($joinedParticipant);
        $this->assertSame(ConferenceParticipantStatus::Joined->value, $joinedParticipant['status']);

        Sanctum::actingAs($host);

        $endResponse = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoomPublicId}/end",
        );

        $endResponse->assertOk()
            ->assertJsonPath('data.status', ConferenceRoomStatus::Ended->value);

        $this->assertSame(ConferenceRoomStatus::Ended, ConferenceRoom::query()->sole()->status);
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
}
