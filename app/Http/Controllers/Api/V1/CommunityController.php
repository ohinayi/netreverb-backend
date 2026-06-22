<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CommunityMembershipRole;
use App\Enums\CommunityMembershipStatus;
use App\Enums\CommunityVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InviteCommunityMemberRequest;
use App\Http\Requests\Api\V1\StoreCommunityDepartmentRequest;
use App\Http\Requests\Api\V1\StoreCommunityRequest;
use App\Http\Resources\Api\V1\CommunityDepartmentResource;
use App\Http\Resources\Api\V1\CommunityMembershipResource;
use App\Http\Resources\Api\V1\CommunityResource;
use App\Models\Community;
use App\Models\CommunityDepartment;
use App\Models\CommunityMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommunityController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $communities = Community::query()
            ->where(function ($query) use ($request): void {
                $query->where('owner_user_id', $request->user()->id)
                    ->orWhereHas('memberships', fn ($membershipQuery) => $membershipQuery->where('user_id', $request->user()->id));
            })
            ->with(['owner'])
            ->withCount(['memberships', 'departments'])
            ->latest()
            ->paginate(20);

        return CommunityResource::collection($communities);
    }

    public function store(StoreCommunityRequest $request): JsonResponse
    {
        Gate::authorize('create', Community::class);

        $community = Community::query()->create([
            'owner_user_id' => $request->user()->id,
            'name' => $request->string('name')->toString(),
            'slug' => $request->filled('slug')
                ? $request->string('slug')->toString()
                : Str::slug($request->string('name')->toString()),
            'description' => $request->input('description'),
            'visibility' => $request->filled('visibility')
                ? CommunityVisibility::from($request->string('visibility')->toString())
                : CommunityVisibility::Private,
            'settings' => $request->input('settings'),
        ]);

        CommunityMembership::query()->create([
            'community_id' => $community->id,
            'user_id' => $request->user()->id,
            'role' => CommunityMembershipRole::Owner,
            'status' => CommunityMembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return CommunityResource::make($community->load('owner')->loadCount(['memberships', 'departments']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Community $community): CommunityResource
    {
        Gate::authorize('view', $community);

        return CommunityResource::make(
            $community->load(['owner', 'departments', 'memberships.user'])->loadCount(['memberships', 'departments']),
        );
    }

    public function join(Request $request, Community $community): CommunityMembershipResource
    {
        Gate::authorize('join', $community);

        if ($community->visibility === CommunityVisibility::InviteOnly) {
            throw ValidationException::withMessages([
                'community' => 'This community is invite only.',
            ]);
        }

        $membership = CommunityMembership::query()->updateOrCreate(
            [
                'community_id' => $community->id,
                'user_id' => $request->user()->id,
            ],
            [
                'role' => CommunityMembershipRole::Member,
                'status' => CommunityMembershipStatus::Active,
                'joined_at' => now(),
                'left_at' => null,
            ],
        );

        return CommunityMembershipResource::make($membership->load(['user', 'communityDepartment']));
    }

    public function invite(InviteCommunityMemberRequest $request, Community $community): CommunityMembershipResource
    {
        Gate::authorize('invite', $community);

        $user = null;
        if ($request->filled('user_public_id')) {
            $user = User::query()
                ->where('public_id', $request->string('user_public_id')->toString())
                ->firstOrFail();
        }

        $department = null;
        if ($request->filled('community_department_public_id')) {
            $department = CommunityDepartment::query()
                ->where('public_id', $request->string('community_department_public_id')->toString())
                ->whereBelongsTo($community)
                ->firstOrFail();
        }

        $membership = CommunityMembership::query()->updateOrCreate(
            [
                'community_id' => $community->id,
                'user_id' => $user?->id,
            ],
            [
                'community_department_id' => $department?->id,
                'invited_by_user_id' => $request->user()->id,
                'role' => $request->filled('role')
                    ? CommunityMembershipRole::from($request->string('role')->toString())
                    : CommunityMembershipRole::Member,
                'status' => CommunityMembershipStatus::Invited,
                'joined_at' => null,
                'left_at' => null,
            ],
        );

        return CommunityMembershipResource::make($membership->load(['user', 'communityDepartment']));
    }

    public function storeDepartment(
        StoreCommunityDepartmentRequest $request,
        Community $community,
    ): CommunityDepartmentResource {
        Gate::authorize('update', $community);

        $department = $community->departments()->create([
            'name' => $request->string('name')->toString(),
            'slug' => $request->filled('slug')
                ? $request->string('slug')->toString()
                : Str::slug($request->string('name')->toString()),
            'description' => $request->input('description'),
            'color' => $request->input('color'),
        ]);

        return CommunityDepartmentResource::make($department);
    }

    public function assignDepartment(Request $request, Community $community, User $user): CommunityMembershipResource
    {
        Gate::authorize('update', $community);

        $request->validate([
            'community_department_public_id' => ['required', 'string', 'exists:community_departments,public_id'],
        ]);

        $department = CommunityDepartment::query()
            ->where('public_id', $request->string('community_department_public_id')->toString())
            ->where('community_id', $community->id)
            ->firstOrFail();

        $membership = CommunityMembership::query()
            ->where('community_id', $community->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $membership->forceFill([
            'community_department_id' => $department->id,
        ])->save();

        return CommunityMembershipResource::make($membership->load(['user', 'communityDepartment']));
    }

    public function destroy(Community $community): Response
    {
        Gate::authorize('delete', $community);
        $community->delete();

        return response()->noContent();
    }
}
