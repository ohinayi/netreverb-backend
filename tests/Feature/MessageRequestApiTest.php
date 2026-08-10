<?php

namespace Tests\Feature;

use App\Enums\FriendshipStatus;
use App\Enums\MessageRequestStatus;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\MessageRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessageRequestApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_can_send_a_single_message_request_to_non_friend(): void
    {
        $sender = User::factory()->create(['email_verified_at' => now()]);
        $recipient = User::factory()->create(['email_verified_at' => now()]);

        Sanctum::actingAs($sender);

        // Send first message request
        $response = $this->postJson('/api/v1/message-requests', [
            'recipient_public_id' => $recipient->public_id,
            'body' => 'Hey, nice to meet you!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.body', 'Hey, nice to meet you!')
            ->assertJsonPath('data.status', MessageRequestStatus::Pending->value);

        $this->assertSame(1, MessageRequest::query()->count());

        // Attempting to send a second message request to same recipient must fail (409 Conflict)
        $secondResponse = $this->postJson('/api/v1/message-requests', [
            'recipient_public_id' => $recipient->public_id,
            'body' => 'Are you there?',
        ]);

        $secondResponse->assertStatus(409);
        $this->assertSame(1, MessageRequest::query()->count());

        // Check endpoint should report pending
        $checkResponse = $this->getJson("/api/v1/message-requests/check?recipient_public_id={$recipient->public_id}");
        $checkResponse->assertOk()
            ->assertJsonPath('has_pending', true);
    }

    public function test_cannot_send_message_request_if_already_friends(): void
    {
        $sender = User::factory()->create(['email_verified_at' => now()]);
        $recipient = User::factory()->create(['email_verified_at' => now()]);

        Friendship::query()->create([
            'requester_id' => $sender->id,
            'addressee_id' => $recipient->id,
            'status' => FriendshipStatus::Accepted,
            'requested_at' => now(),
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($sender);

        $response = $this->postJson('/api/v1/message-requests', [
            'recipient_public_id' => $recipient->public_id,
            'body' => 'Hello friend',
        ]);

        $response->assertStatus(403);
    }

    public function test_recipient_can_accept_message_request_and_unlock_conversation(): void
    {
        $sender = User::factory()->create(['email_verified_at' => now()]);
        $recipient = User::factory()->create(['email_verified_at' => now()]);

        $messageRequest = MessageRequest::query()->create([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => $recipient->id,
            'body' => 'Hi, can we talk?',
            'status' => MessageRequestStatus::Pending,
        ]);

        Sanctum::actingAs($recipient);

        // List received requests
        $listResponse = $this->getJson('/api/v1/message-requests/received');
        $listResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $messageRequest->public_id);

        // Accept request
        $acceptResponse = $this->postJson("/api/v1/message-requests/{$messageRequest->public_id}/accept");
        $acceptResponse->assertOk();

        $conversationId = $acceptResponse->json('data.conversation_id');
        $this->assertNotEmpty($conversationId);

        $conversation = Conversation::query()->where('public_id', $conversationId)->firstOrFail();
        $this->assertSame(1, Message::query()->where('conversation_id', $conversation->id)->count());

        // Sender and recipient can now message each other in unlocked conversation
        $messageResponse = $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages", [
            'body' => 'Thanks for accepting!',
            'type' => 'text',
        ]);
        $messageResponse->assertCreated();
    }

    public function test_recipient_can_decline_message_request(): void
    {
        $sender = User::factory()->create(['email_verified_at' => now()]);
        $recipient = User::factory()->create(['email_verified_at' => now()]);

        $messageRequest = MessageRequest::query()->create([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => $recipient->id,
            'body' => 'Spam message',
            'status' => MessageRequestStatus::Pending,
        ]);

        Sanctum::actingAs($recipient);

        $deleteResponse = $this->deleteJson("/api/v1/message-requests/{$messageRequest->public_id}");
        $deleteResponse->assertNoContent();

        $this->assertSame(0, MessageRequest::query()->count());
    }
}
