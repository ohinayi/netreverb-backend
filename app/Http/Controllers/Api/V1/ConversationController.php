<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConversationKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreConversationRequest;
use App\Http\Resources\Api\V1\ConversationResource;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
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
            ->with(['community', 'participants.user'])
            ->withCount('participants')
            ->latest('last_message_at')
            ->paginate(20);

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

        if ($kind === ConversationKind::Community) {
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
        } else {
            $community = null;
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
