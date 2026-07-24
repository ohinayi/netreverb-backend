<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Organizations\AddOrganizationMember;
use App\Actions\Organizations\CreateOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InviteOrganizationMemberRequest;
use App\Http\Requests\Api\V1\StoreOrganizationRequest;
use App\Http\Requests\Api\V1\UpdateOrganizationRequest;
use App\Http\Resources\Api\V1\OrganizationMembershipResource;
use App\Http\Resources\Api\V1\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class OrganizationController extends Controller
{
    public function __construct(
        private CreateOrganization $createOrganization,
        private AddOrganizationMember $addOrganizationMember,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Organization::class);

        $organizations = Organization::query()
            ->visibleTo($request->user())
            ->withCount(['memberships', 'extensions'])
            ->latest()
            ->paginate(20);

        return OrganizationResource::collection($organizations);
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        Gate::authorize('create', Organization::class);

        return OrganizationResource::make(
            $this->createOrganization->execute($request->user(), $request->validated()),
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Organization $organization): OrganizationResource
    {
        Gate::authorize('view', $organization);

        return OrganizationResource::make($organization->loadCount(['memberships', 'extensions']));
    }

    public function update(
        UpdateOrganizationRequest $request,
        Organization $organization,
    ): OrganizationResource {
        Gate::authorize('update', $organization);
        $organization->update($request->validated());

        return OrganizationResource::make($organization->refresh());
    }

    public function members(Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('view', $organization);

        return OrganizationMembershipResource::collection(
            $organization->memberships()->with(['user', 'department'])->latest()->get(),
        );
    }

    public function inviteMember(
        InviteOrganizationMemberRequest $request,
        Organization $organization,
    ): JsonResponse {
        Gate::authorize('update', $organization);

        $membership = $this->addOrganizationMember->execute(
            $organization,
            $request->user(),
            $request->validated(),
        );

        return OrganizationMembershipResource::make($membership->load(['user', 'department']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
