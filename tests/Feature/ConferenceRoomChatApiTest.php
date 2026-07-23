<?php

namespace Tests\Feature;

use App\Enums\ConferenceParticipantStatus;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ConferenceRooms\ConferenceRoomChatService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConferenceRoomChatApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_active_participant_can_bootstrap_send_and_retrieve_chat_history(): void
    {
        $context = $this->createChatRoomContext();
        $appUrl = rtrim((string) config('app.url'), '/');
        Sanctum::actingAs($context['participant']);

        $bootstrapResponse = $this->getJson("/api/v1/conference-rooms/{$context['conferenceRoom']->public_id}/chat");

        $bootstrapResponse->assertOk()
            ->assertJsonPath('type', 'conference.chat.bootstrap')
            ->assertJsonPath('data.channel_name', 'private-conference.chat.'.$context['conferenceRoom']->public_id)
            ->assertJsonPath('data.websocket_url', $appUrl.'/api/v1/conference-rooms/'.$context['conferenceRoom']->public_id.'/chat')
            ->assertJsonPath('data.stream_url', $appUrl.'/api/v1/conference-rooms/'.$context['conferenceRoom']->public_id.'/chat/stream')
            ->assertJsonPath('data.messages_url', $appUrl.'/api/v1/conference-rooms/'.$context['conferenceRoom']->public_id.'/chat/messages')
            ->assertJsonPath('data.history', []);

        $messageResponse = $this->postJson("/api/v1/conference-rooms/{$context['conferenceRoom']->public_id}/chat/messages", [
            'body' => '   Hello everyone   ',
        ]);

        $messageResponse->assertCreated()
            ->assertJsonPath('type', 'conference.chat.message')
            ->assertJsonPath('data.conference_room_public_id', $context['conferenceRoom']->public_id)
            ->assertJsonPath('data.participant_public_id', $context['participantRoomParticipant']->public_id)
            ->assertJsonPath('data.display_name', 'Remote Guest')
            ->assertJsonPath('data.body', 'Hello everyone');

        $historyResponse = $this->getJson("/api/v1/conference-rooms/{$context['conferenceRoom']->public_id}/chat");

        $historyResponse->assertOk()
            ->assertJsonCount(1, 'data.history')
            ->assertJsonPath('data.history.0.body', 'Hello everyone');

        $this->assertCount(1, app(ConferenceRoomChatService::class)->history($context['conferenceRoom']));
    }

    public function test_waiting_participant_cannot_access_chat(): void
    {
        $context = $this->createChatRoomContext(participantStatus: ConferenceParticipantStatus::Waiting);
        Sanctum::actingAs($context['participant']);

        $this->getJson("/api/v1/conference-rooms/{$context['conferenceRoom']->public_id}/chat")
            ->assertForbidden()
            ->assertJsonPath('type', 'error')
            ->assertJsonPath('error_code', 'conference_chat_access_denied');

        $this->postJson("/api/v1/conference-rooms/{$context['conferenceRoom']->public_id}/chat/messages", [
            'body' => 'Blocked',
        ])
            ->assertForbidden()
            ->assertJsonPath('type', 'error')
            ->assertJsonPath('error_code', 'conference_chat_access_denied');
    }

    public function test_chat_messages_are_rate_limited_per_participant(): void
    {
        $context = $this->createChatRoomContext();
        Sanctum::actingAs($context['participant']);

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson("/api/v1/conference-rooms/{$context['conferenceRoom']->public_id}/chat/messages", [
                'body' => "Message {$i}",
            ])->assertCreated();
        }

        $this->postJson("/api/v1/conference-rooms/{$context['conferenceRoom']->public_id}/chat/messages", [
            'body' => 'Message 11',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_room_end_clears_ephemeral_chat_buffer(): void
    {
        $context = $this->createChatRoomContext();

        Sanctum::actingAs($context['participant']);

        $this->postJson("/api/v1/conference-rooms/{$context['conferenceRoom']->public_id}/chat/messages", [
            'body' => 'Before end',
        ])->assertCreated();

        $this->assertCount(1, app(ConferenceRoomChatService::class)->history($context['conferenceRoom']));

        Sanctum::actingAs($context['host']);

        $this->postJson("/api/v1/organizations/{$context['organization']->public_id}/conference-rooms/{$context['conferenceRoom']->public_id}/end")
            ->assertOk();

        $this->assertCount(0, app(ConferenceRoomChatService::class)->history($context['conferenceRoom']));
    }

    /**
     * @return array{
     *     organization: Organization,
     *     host: User,
     *     participant: User,
     *     conferenceRoom: ConferenceRoom,
     *     hostRoomParticipant: ConferenceRoomParticipant,
     *     participantRoomParticipant: ConferenceRoomParticipant
     * }
     */
    private function createChatRoomContext(
        ConferenceParticipantStatus $participantStatus = ConferenceParticipantStatus::Joined,
    ): array {
        $organization = Organization::factory()->create();
        $host = User::factory()->create();
        $participant = User::factory()->create();

        OrganizationMembership::factory()->admin()->for($organization)->for($host)->create();
        OrganizationMembership::factory()->for($organization)->for($participant)->create();

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($host, 'hostUser')->create([
            'expires_at' => now()->addHour(),
        ]);

        $hostRoomParticipant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($host)->create([
            'status' => ConferenceParticipantStatus::Joined,
            'role' => 'host',
            'display_name' => $host->name,
            'email' => $host->email,
            'joined_at' => now()->subMinutes(2),
        ]);

        $participantRoomParticipant = ConferenceRoomParticipant::factory()->for($conferenceRoom)->for($participant)->create([
            'status' => $participantStatus,
            'role' => 'participant',
            'display_name' => 'Remote Guest',
            'email' => $participant->email,
            'joined_at' => $participantStatus === ConferenceParticipantStatus::Joined ? now()->subMinute() : null,
            'left_at' => null,
        ]);

        return [
            'organization' => $organization,
            'host' => $host,
            'participant' => $participant,
            'conferenceRoom' => $conferenceRoom,
            'hostRoomParticipant' => $hostRoomParticipant,
            'participantRoomParticipant' => $participantRoomParticipant,
        ];
    }
}
