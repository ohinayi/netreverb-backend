<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMessageRequest;
use App\Http\Resources\Api\V1\MessageResource;
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

        ConversationParticipant::query()->firstOrCreate([
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()->id,
        ], [
            'role' => 'member',
            'joined_at' => now(),
        ]);

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $request->user()->id,
            'type' => $request->filled('type') ? $request->string('type')->toString() : 'text',
            'body' => $request->input('body'),
            'attachment_path' => $request->input('attachment_path'),
            'metadata' => $request->input('metadata'),
            'sent_at' => now(),
        ]);

        $conversation->forceFill([
            'last_message_at' => $message->sent_at,
        ])->save();

        return MessageResource::make($message->load('senderUser'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
