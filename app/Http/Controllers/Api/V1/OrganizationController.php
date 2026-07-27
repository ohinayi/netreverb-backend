<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Organizations\AddOrganizationMember;
use App\Actions\Organizations\CreateOrganization;
use App\Actions\Organizations\SyncOrganizationMemberFriendships;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InviteOrganizationMemberRequest;
use App\Http\Requests\Api\V1\StoreOrganizationRequest;
use App\Http\Requests\Api\V1\UpdateOrganizationMemberRequest;
use App\Http\Requests\Api\V1\UpdateOrganizationRequest;
use App\Http\Resources\Api\V1\OrganizationMembershipResource;
use App\Http\Resources\Api\V1\OrganizationResource;
use App\Models\Department;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Services\Auditing\AuditLogger;
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
        private SyncOrganizationMemberFriendships $syncFriendships,
        private AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Organization::class);

        $organizations = Organization::query()
            ->visibleTo($request->user())
            ->with([
                'memberships' => fn ($query) => $query->whereBelongsTo($request->user()),
            ])
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

        return OrganizationResource::make($organization->load([
            'memberships' => fn ($query) => $query->whereBelongsTo(request()->user()),
        ])->loadCount(['memberships', 'extensions']));
    }

    public function update(
        UpdateOrganizationRequest $request,
        Organization $organization,
    ): OrganizationResource {
        Gate::authorize('update', $organization);
        $before = $organization->only(['name', 'slug', 'timezone', 'locale', 'settings']);
        $organization->update($request->validated());
        $this->auditLogger->record(
            $request,
            $request->user(),
            $organization,
            'organization.updated',
            $organization,
            $before,
            $organization->fresh()->only(['name', 'slug', 'timezone', 'locale', 'settings']),
        );

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
        $this->auditLogger->record(
            $request,
            $request->user(),
            $organization,
            'organization.member.invited',
            $membership,
            after: $this->membershipAuditData($membership),
        );

        return OrganizationMembershipResource::make($membership->load(['user', 'department']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function updateMember(
        UpdateOrganizationMemberRequest $request,
        Organization $organization,
        OrganizationMembership $membership,
    ): OrganizationMembershipResource {
        Gate::authorize('manageMembers', $organization);

        abort_unless($membership->organization_id === $organization->id, Response::HTTP_NOT_FOUND);

        $before = $this->membershipAuditData($membership);
        $data = $request->validated();
        if (array_key_exists('department_public_id', $data)) {
            $data['department_id'] = $data['department_public_id'] === null
                ? null
                : Department::query()
                    ->whereBelongsTo($organization)
                    ->where('public_id', $data['department_public_id'])
                    ->valueOrFail('id');
            unset($data['department_public_id']);
        }
        if (isset($data['role'])) {
            $data['role'] = MembershipRole::from($data['role']);
        }
        if (isset($data['status'])) {
            $data['status'] = MembershipStatus::from($data['status']);
        }

        $membership->update($data);

        if ($membership->status === MembershipStatus::Active) {
            $this->syncFriendships->execute($organization, $membership->user);
        }

        $membership->refresh();
        $after = $this->membershipAuditData($membership);
        if ($before !== $after) {
            $this->auditLogger->record(
                $request,
                $request->user(),
                $organization,
                'organization.member.updated',
                $membership,
                $before,
                $after,
            );
        }

        return OrganizationMembershipResource::make($membership->load(['user', 'department']));
    }

    /** @return array{role: string, status: string, department_id: ?int} */
    private function membershipAuditData(OrganizationMembership $membership): array
    {
        return [
            'role' => $membership->role?->value ?? (string) $membership->role,
            'status' => $membership->status?->value ?? (string) $membership->status,
            'department_id' => $membership->department_id,
        ];
    }
}
