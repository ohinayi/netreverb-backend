<?php

namespace Tests\Feature;

use App\Enums\ConversationKind;
use App\Enums\FriendshipStatus;
use App\Enums\MessageType;
use App\Exceptions\Messaging\InvalidVoiceMessageException;
use App\Jobs\VerifyVoiceMessageMedia;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VoiceMessageApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createDirectConversation(): array
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

        $conversationResponse = $this->postJson('/api/v1/conversations', [
            'kind' => ConversationKind::Direct->value,
            'participant_public_ids' => [$recipient->public_id],
        ])->assertCreated();

        return [$sender, $recipient, Conversation::query()->sole()];
    }

    public function test_user_can_upload_a_voice_message(): void
    {
        Storage::fake('public');
        Queue::fake();

        [$sender, , $conversation] = $this->createDirectConversation();

        $audio = UploadedFile::fake()->create('recording.webm', 128, 'audio/webm');

        $response = $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages/voice", [
            'audio' => $audio,
            'duration_ms' => 4200,
            'waveform' => [0.1, 0.5, 0.9, 0.3],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', MessageType::VoiceNote->value)
            ->assertJsonPath('data.body', null)
            ->assertJsonPath('data.metadata.duration_ms', 4200)
            ->assertJsonPath('data.metadata.mime_type', 'audio/webm')
            ->assertJsonPath('data.metadata.transcript_status', 'pending');

        $message = Message::query()->sole();
        $this->assertSame($sender->id, $message->sender_user_id);
        $this->assertSame(MessageType::VoiceNote, $message->type);
        $this->assertNotNull($message->attachment_path);
        Storage::disk('public')->assertExists($message->attachment_path);

        $this->assertTrue($conversation->fresh()->last_message_at->equalTo($message->sent_at));

        Queue::assertPushed(VerifyVoiceMessageMedia::class, fn (VerifyVoiceMessageMedia $job): bool => $job->messagePublicId === $message->public_id);
    }

    public function test_voice_message_upload_rejects_a_disallowed_mime_type(): void
    {
        Storage::fake('public');
        Queue::fake();

        [, , $conversation] = $this->createDirectConversation();

        $response = $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages/voice", [
            'audio' => UploadedFile::fake()->create('recording.txt', 10, 'text/plain'),
            'duration_ms' => 2000,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('audio');
        $this->assertSame(0, Message::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_voice_message_upload_rejects_a_recording_longer_than_the_max_duration(): void
    {
        Storage::fake('public');
        Queue::fake();

        [, , $conversation] = $this->createDirectConversation();

        $response = $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages/voice", [
            'audio' => UploadedFile::fake()->create('recording.webm', 128, 'audio/webm'),
            'duration_ms' => config('messaging.voice_messages.max_duration_ms') + 1000,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('duration_ms');
        $this->assertSame(0, Message::query()->count());
    }

    public function test_voice_message_upload_rejects_a_file_over_the_size_limit(): void
    {
        Storage::fake('public');
        Queue::fake();

        [, , $conversation] = $this->createDirectConversation();

        $oversizeKb = config('messaging.voice_messages.max_file_size_kb') + 512;

        $response = $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages/voice", [
            'audio' => UploadedFile::fake()->create('recording.webm', $oversizeKb, 'audio/webm'),
            'duration_ms' => 2000,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('audio');
        $this->assertSame(0, Message::query()->count());
    }

    public function test_voice_message_upload_requires_the_audio_file(): void
    {
        Storage::fake('public');

        [, , $conversation] = $this->createDirectConversation();

        $response = $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages/voice", [
            'duration_ms' => 2000,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('audio');
    }

    public function test_a_non_participant_cannot_upload_a_voice_message_without_an_accepted_friendship(): void
    {
        Storage::fake('public');

        $outsider = User::factory()->create(['email_verified_at' => now()]);
        [, , $conversation] = $this->createDirectConversation();

        Sanctum::actingAs($outsider);

        $response = $this->postJson("/api/v1/conversations/{$conversation->public_id}/messages/voice", [
            'audio' => UploadedFile::fake()->create('recording.webm', 128, 'audio/webm'),
            'duration_ms' => 2000,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Message::query()->count());
    }

    public function test_creating_a_voice_message_without_an_attachment_path_is_rejected_at_the_model_level(): void
    {
        [, , $conversation] = $this->createDirectConversation();

        $this->expectException(InvalidVoiceMessageException::class);

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $conversation->participants()->first()?->user_id,
            'type' => MessageType::VoiceNote,
            'body' => null,
            'attachment_path' => null,
            'sent_at' => now(),
        ]);
    }
}
