<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FriendshipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RespondFriendRequest;
use App\Http\Requests\Api\V1\StoreFriendRequest;
use App\Http\Resources\Api\V1\FriendshipResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class FriendshipController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $friendships = Friendship::query()
            ->where(function ($query) use ($request): void {
                $query->where('requester_id', $request->user()->id)
                    ->orWhere('addressee_id', $request->user()->id);
            })
            ->with(['requester', 'addressee'])
            ->latest()
            ->paginate(25);

        return FriendshipResource::collection($friendships);
    }

    public function search(Request $request): AnonymousResourceCollection
    {
        $search = $request->string('q')->toString();

        $users = User::query()
            ->when(
                $search !== '',
                fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('public_id', 'like', "%{$search}%");
                }),
            )
            ->whereKeyNot($request->user()->id)
            ->latest()
            ->limit(20)
            ->get();

        return UserResource::collection($users);
    }

    public function store(StoreFriendRequest $request): JsonResponse
    {
        Gate::authorize('create', Friendship::class);

        $requester = $request->user();
        $addressee = User::query()
            ->where('public_id', $request->string('addressee_public_id')->toString())
            ->firstOrFail();

        if ($requester->is($addressee)) {
            throw ValidationException::withMessages([
                'addressee_public_id' => 'You cannot friend yourself.',
            ]);
        }

        $friendship = Friendship::query()
            ->where(function ($query) use ($requester, $addressee): void {
                $query->where('requester_id', $requester->id)
                    ->where('addressee_id', $addressee->id);
            })
            ->orWhere(function ($query) use ($requester, $addressee): void {
                $query->where('requester_id', $addressee->id)
                    ->where('addressee_id', $requester->id);
            })
            ->first();

        if ($friendship !== null && $friendship->status === FriendshipStatus::Accepted) {
            return FriendshipResource::make($friendship->load(['requester', 'addressee']))
                ->response()
                ->setStatusCode(Response::HTTP_OK);
        }

        if ($friendship !== null && $friendship->status === FriendshipStatus::Pending) {
            throw ValidationException::withMessages([
                'addressee_public_id' => 'A friend request already exists.',
            ]);
        }

        $friendship = Friendship::query()->create([
            'requester_id' => $requester->id,
            'addressee_id' => $addressee->id,
            'status' => FriendshipStatus::Pending,
            'requested_at' => now(),
            'note' => $request->input('note'),
        ]);

        return FriendshipResource::make($friendship->load(['requester', 'addressee']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Friendship $friendship): FriendshipResource
    {
        Gate::authorize('view', $friendship);

        return FriendshipResource::make($friendship->load(['requester', 'addressee']));
    }

    public function update(RespondFriendRequest $request, Friendship $friendship): FriendshipResource
    {
        Gate::authorize('update', $friendship);

        $decision = $request->string('decision')->toString();
        $friendship->forceFill([
            'status' => FriendshipStatus::from($decision),
            'responded_at' => now(),
        ])->save();

        return FriendshipResource::make($friendship->refresh()->load(['requester', 'addressee']));
    }

    public function destroy(Request $request, Friendship $friendship): Response
    {
        Gate::authorize('delete', $friendship);
        $friendship->delete();

        return response()->noContent();
    }
}
