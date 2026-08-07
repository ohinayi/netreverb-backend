<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MessageTranslationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_translates_a_text_message_without_persisting_anything(): void
    {
        config()->set('translation.provider', 'libretranslate');
        config()->set('translation.providers.libretranslate.base_url', 'http://libretranslate.test');

        Http::fake([
            'http://libretranslate.test/detect' => Http::response([
                [
                    'language' => 'en',
                    'confidence' => 0.99,
                ],
            ]),
            'http://libretranslate.test/translate' => Http::response([
                'translatedText' => 'Bonjour tout le monde',
                'detectedLanguage' => [
                    'language' => 'en',
                    'confidence' => 99.9,
                ],
            ]),
        ]);

        $user = User::factory()->create(['locale' => 'fr']);
        $conversation = Conversation::factory()->create();
        ConversationParticipant::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => User::factory()->create()->id,
            'body' => 'Hello world',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->public_id}/messages/{$message->public_id}/translate", [
                'target_locale' => 'fr',
            ])
            ->assertOk()
            ->assertJson([
                'translated_text' => 'Bonjour tout le monde',
                'source_language' => 'en',
                'target_language' => 'fr',
            ]);

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'body' => 'Hello world',
        ]);

        Http::assertSent(fn ($request) => $request->url() === 'http://libretranslate.test/translate'
            && $request['q'] === 'Hello world'
            && $request['source'] === 'en'
            && $request['target'] === 'fr');
    }

    public function test_it_detects_non_english_source_text_before_translating(): void
    {
        config()->set('translation.provider', 'libretranslate');
        config()->set('translation.providers.libretranslate.base_url', 'http://libretranslate.test');

        Http::fake([
            'http://libretranslate.test/detect' => Http::response([
                [
                    'language' => 'zh',
                    'confidence' => 0.99,
                ],
            ]),
            'http://libretranslate.test/translate' => Http::response([
                'translatedText' => 'Good morning',
                'detectedLanguage' => [
                    'language' => 'zh',
                    'confidence' => 0.99,
                ],
            ]),
        ]);

        $user = User::factory()->create(['locale' => 'en']);
        $conversation = Conversation::factory()->create();
        ConversationParticipant::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => User::factory()->create()->id,
            'body' => '早上好',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->public_id}/messages/{$message->public_id}/translate", [
                'target_locale' => 'en',
            ])
            ->assertOk()
            ->assertJson([
                'translated_text' => 'Good morning',
                'source_language' => 'zh',
                'target_language' => 'en',
            ]);

        Http::assertSent(fn ($request) => $request->url() === 'http://libretranslate.test/translate'
            && $request['q'] === '早上好'
            && $request['source'] === 'zh'
            && $request['target'] === 'en');
    }

    public function test_it_rejects_non_text_messages(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create();
        ConversationParticipant::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $user->id,
            'type' => 'file',
            'attachment_path' => 'uploads/file.pdf',
            'body' => null,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->public_id}/messages/{$message->public_id}/translate")
            ->assertStatus(422);
    }
}
