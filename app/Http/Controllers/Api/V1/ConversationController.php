<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConversationKind;
use App\Enums\FriendshipStatus;
use App\Enums\MessageRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreConversationRequest;
use App\Http\Resources\Api\V1\ConversationResource;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Friendship;
use App\Models\MessageRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $conversations = Conversation::query()
            ->whereHas('participants', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with(['community', 'participants.user', 'lastMessage.senderUser'])
            ->withCount('participants')
            ->latest('last_message_at')
            ->get()
            ->filter(fn (Conversation $conversation) => Gate::allows('view', $conversation))
            ->values();

        return ConversationResource::collection($conversations);
    }

    public function store(StoreConversationRequest $request): JsonResponse
    {
        Gate::authorize('create', Conversation::class);

        $kind = ConversationKind::from($request->string('kind')->toString());
        $participantIds = collect($request->input('participant_public_ids', []))
            ->map(fn (string $publicId): int => User::query()->where('public_id', $publicId)->value('id'))
            ->filter()
            ->values();

        $participantIds->push($request->user()->id);
        $participantIds = $participantIds->unique()->sort()->values();

        if ($kind === ConversationKind::Direct && $participantIds->count() !== 2) {
            throw ValidationException::withMessages([
                'participant_public_ids' => 'Direct conversations require exactly one other participant.',
            ]);
        }

        if ($kind === ConversationKind::Direct) {
            $otherUserId = $participantIds->first(fn (int $id): bool => $id !== $request->user()->id);

            $isFriend = Friendship::query()
                ->where('status', FriendshipStatus::Accepted)
                ->where(function ($query) use ($request, $otherUserId): void {
                    $query->where(function ($q) use ($request, $otherUserId): void {
                        $q->where('requester_id', $request->user()->id)
                            ->where('addressee_id', $otherUserId);
                    })->orWhere(function ($q) use ($request, $otherUserId): void {
                        $q->where('requester_id', $otherUserId)
                            ->where('addressee_id', $request->user()->id);
                    });
                })
                ->exists();

            $hasAcceptedRequest = MessageRequest::query()
                ->where('status', MessageRequestStatus::Accepted)
                ->where(function ($query) use ($request, $otherUserId): void {
                    $query->where(function ($q) use ($request, $otherUserId): void {
                        $q->where('sender_user_id', $request->user()->id)
                            ->where('recipient_user_id', $otherUserId);
                    })->orWhere(function ($q) use ($request, $otherUserId): void {
                        $q->where('sender_user_id', $otherUserId)
                            ->where('recipient_user_id', $request->user()->id);
                    });
                })
                ->exists();

            if (! $isFriend && ! $hasAcceptedRequest) {
                throw ValidationException::withMessages([
                    'participant_public_ids' => 'Messaging is restricted to accepted friends or accepted message requests.',
                ]);
            }
        }

        $community = null;
        if ($kind === ConversationKind::Community && $request->has('community_public_id') && $request->filled('community_public_id')) {
            $community = Community::query()
                ->where('public_id', $request->string('community_public_id')->toString())
                ->firstOrFail();

            $membership = CommunityMembership::query()
                ->where('community_id', $community->id)
                ->where('user_id', $request->user()->id)
                ->where('status', 'active')
                ->first();

            Gate::authorize('view', $community);

            if ($membership === null) {
                throw ValidationException::withMessages([
                    'community_public_id' => 'You are not a member of this community.',
                ]);
            }
        }

        $directKey = $kind === ConversationKind::Direct
            ? $participantIds->join(':')
            : null;

        if ($kind === ConversationKind::Direct) {
            $conversation = Conversation::query()->firstOrCreate(
                ['direct_key' => $directKey],
                [
                    'created_by_user_id' => $request->user()->id,
                    'kind' => $kind,
                    'title' => $request->input('title'),
                ],
            );
        } else {
            $conversation = Conversation::query()->create([
                'community_id' => $community?->id,
                'created_by_user_id' => $request->user()->id,
                'kind' => $kind,
                'title' => $request->input('title'),
            ]);
        }

        if ($conversation->wasRecentlyCreated || $kind !== ConversationKind::Direct) {
            $participantIds->each(function (int $userId) use ($conversation): void {
                ConversationParticipant::query()->updateOrCreate(
                    [
                        'conversation_id' => $conversation->id,
                        'user_id' => $userId,
                    ],
                    [
                        'role' => $userId === $conversation->created_by_user_id ? 'owner' : 'member',
                        'joined_at' => now(),
                        'left_at' => null,
                        'muted_at' => null,
                    ],
                );
            });
        }

        return ConversationResource::make(
            $conversation->load(['community', 'participants.user'])->loadCount('participants'),
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Conversation $conversation): ConversationResource
    {
        Gate::authorize('view', $conversation);

        ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $request->user()->id)
            ->update(['last_read_at' => now()]);

        return ConversationResource::make(
            $conversation->load(['community', 'participants.user', 'messages.senderUser'])->loadCount('participants'),
        );
    }

    public function destroy(Request $request, Conversation $conversation): Response
    {
        Gate::authorize('delete', $conversation);
        $conversation->delete();

        return response()->noContent();
    }
}
