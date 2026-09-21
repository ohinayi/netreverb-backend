<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MessageType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMessageRequest;
use App\Http\Requests\Api\V1\StoreVoiceMessageRequest;
use App\Http\Resources\Api\V1\MessageResource;
use App\Jobs\VerifyVoiceMessageMedia;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class MessageController extends Controller
{
    public function store(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('send', $conversation);

        $this->joinAsParticipant($conversation, $request->user()->id);

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $request->user()->id,
            'type' => $request->filled('type') ? $request->string('type')->toString() : 'text',
            'body' => $request->input('body'),
            'attachment_path' => $request->input('attachment_path'),
            'metadata' => $request->input('metadata'),
            'sent_at' => now(),
        ]);

        $this->touchLastMessageAt($conversation, $message);

        return MessageResource::make($message->load('senderUser'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function storeVoice(StoreVoiceMessageRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('send', $conversation);

        $this->joinAsParticipant($conversation, $request->user()->id);

        $audio = $request->file('audio');
        $disk = config('messaging.voice_messages.disk');
        $path = $audio->store('conversations/'.$conversation->public_id.'/voice-messages', $disk);

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $request->user()->id,
            'type' => MessageType::VoiceNote,
            'body' => null,
            'attachment_path' => $path,
            'metadata' => [
                'duration_ms' => $request->integer('duration_ms'),
                'mime_type' => $audio->getMimeType(),
                'file_size_bytes' => $audio->getSize(),
                'waveform' => $request->input('waveform', []),
                'transcript' => null,
                'transcript_status' => 'pending',
            ],
            'sent_at' => now(),
        ]);

        $this->touchLastMessageAt($conversation, $message);

        VerifyVoiceMessageMedia::dispatch($message->public_id);

        return MessageResource::make($message->load('senderUser'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    private function joinAsParticipant(Conversation $conversation, int $userId): void
    {
        ConversationParticipant::query()->firstOrCreate([
            'conversation_id' => $conversation->id,
            'user_id' => $userId,
        ], [
            'role' => 'member',
            'joined_at' => now(),
        ]);
    }

    private function touchLastMessageAt(Conversation $conversation, Message $message): void
    {
        $conversation->forceFill([
            'last_message_at' => $message->sent_at,
        ])->save();
    }
}
