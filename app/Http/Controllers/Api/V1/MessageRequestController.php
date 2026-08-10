<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConversationKind;
use App\Enums\FriendshipStatus;
use App\Enums\MessageRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMessageRequestApiRequest;
use App\Http\Resources\Api\V1\MessageRequestResource;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\MessageRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class MessageRequestController extends Controller
{
    public function indexReceived(Request $request): AnonymousResourceCollection
    {
        $requests = MessageRequest::query()
            ->where('recipient_user_id', $request->user()->id)
            ->where('status', MessageRequestStatus::Pending)
            ->with(['sender', 'recipient'])
            ->latest()
            ->get();

        return MessageRequestResource::collection($requests);
    }

    public function indexSent(Request $request): AnonymousResourceCollection
    {
        $requests = MessageRequest::query()
            ->where('sender_user_id', $request->user()->id)
            ->where('status', MessageRequestStatus::Pending)
            ->with(['sender', 'recipient'])
            ->latest()
            ->get();

        return MessageRequestResource::collection($requests);
    }

    public function check(Request $request): JsonResponse
    {
        $recipientPublicId = $request->string('recipient_public_id')->toString();
        if ($recipientPublicId === '') {
            return response()->json(['has_pending' => false, 'request' => null]);
        }

        $recipient = User::query()->where('public_id', $recipientPublicId)->first();
        if ($recipient === null) {
            return response()->json(['has_pending' => false, 'request' => null]);
        }

        $pendingRequest = MessageRequest::query()
            ->where('sender_user_id', $request->user()->id)
            ->where('recipient_user_id', $recipient->id)
            ->where('status', MessageRequestStatus::Pending)
            ->with(['sender', 'recipient'])
            ->first();

        return response()->json([
            'has_pending' => $pendingRequest !== null,
            'request' => $pendingRequest !== null ? MessageRequestResource::make($pendingRequest) : null,
        ]);
    }

    public function store(StoreMessageRequestApiRequest $request): JsonResponse
    {
        $sender = $request->user();
        $recipient = User::query()
            ->where('public_id', $request->string('recipient_public_id')->toString())
            ->firstOrFail();

        if ($sender->is($recipient)) {
            throw ValidationException::withMessages([
                'recipient_public_id' => 'You cannot send a message request to yourself.',
            ]);
        }

        // Check if recipient is already an accepted friend
        $isFriend = Friendship::query()
            ->where('status', FriendshipStatus::Accepted)
            ->where(function ($query) use ($sender, $recipient): void {
                $query->where(function ($q) use ($sender, $recipient): void {
                    $q->where('requester_id', $sender->id)
                        ->where('addressee_id', $recipient->id);
                })->orWhere(function ($q) use ($sender, $recipient): void {
                    $q->where('requester_id', $recipient->id)
                        ->where('addressee_id', $sender->id);
                });
            })
            ->exists();

        if ($isFriend) {
            return response()->json([
                'message' => 'User is already a friend; send a direct message instead.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Check if a pending request already exists between sender and recipient
        $existingPending = MessageRequest::query()
            ->where('sender_user_id', $sender->id)
            ->where('recipient_user_id', $recipient->id)
            ->where('status', MessageRequestStatus::Pending)
            ->exists();

        if ($existingPending) {
            return response()->json([
                'message' => 'You have already sent a message request to this user.',
            ], Response::HTTP_CONFLICT);
        }

        $messageRequest = MessageRequest::query()->create([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => $recipient->id,
            'body' => $request->string('body')->toString(),
            'status' => MessageRequestStatus::Pending,
        ]);

        return MessageRequestResource::make($messageRequest->load(['sender', 'recipient']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function accept(Request $request, MessageRequest $messageRequest): JsonResponse
    {
        if ($messageRequest->recipient_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized action.'], Response::HTTP_FORBIDDEN);
        }

        if ($messageRequest->status === MessageRequestStatus::Accepted && $messageRequest->conversation_id !== null) {
            $conversation = Conversation::query()->find($messageRequest->conversation_id);
            if ($conversation !== null) {
                return response()->json([
                    'data' => ['conversation_id' => $conversation->public_id],
                ]);
            }
        }

        if ($messageRequest->status !== MessageRequestStatus::Pending) {
            return response()->json(['message' => 'Request is no longer pending.'], Response::HTTP_BAD_REQUEST);
        }

        $senderId = $messageRequest->sender_user_id;
        $recipientId = $messageRequest->recipient_user_id;

        $participantIds = collect([$senderId, $recipientId])->sort()->values();
        $directKey = $participantIds->join(':');

        $conversation = Conversation::query()->firstOrCreate(
            ['direct_key' => $directKey],
            [
                'created_by_user_id' => $senderId,
                'kind' => ConversationKind::Direct,
                'last_message_at' => now(),
            ],
        );

        // Ensure participants are present
        foreach ([$senderId, $recipientId] as $userId) {
            ConversationParticipant::query()->updateOrCreate(
                [
                    'conversation_id' => $conversation->id,
                    'user_id' => $userId,
                ],
                [
                    'role' => $userId === $conversation->created_by_user_id ? 'owner' : 'member',
                    'joined_at' => now(),
                    'left_at' => null,
                ],
            );
        }

        // Add the message request body as the first message in the conversation thread if not already sent
        $initialMessage = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $senderId,
            'type' => 'text',
            'body' => $messageRequest->body,
            'sent_at' => now(),
        ]);

        $conversation->forceFill([
            'last_message_at' => $initialMessage->sent_at,
        ])->save();

        $messageRequest->forceFill([
            'status' => MessageRequestStatus::Accepted,
            'conversation_id' => $conversation->id,
            'responded_at' => now(),
        ])->save();

        return response()->json([
            'data' => [
                'conversation_id' => $conversation->public_id,
            ],
        ]);
    }

    public function destroy(Request $request, MessageRequest $messageRequest): Response|JsonResponse
    {
        if (
            $messageRequest->recipient_user_id !== $request->user()->id &&
            $messageRequest->sender_user_id !== $request->user()->id
        ) {
            return response()->json(['message' => 'Unauthorized action.'], Response::HTTP_FORBIDDEN);
        }

        $messageRequest->delete();

        return response()->noContent();
    }
}
