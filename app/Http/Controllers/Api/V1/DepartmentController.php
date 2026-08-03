<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDepartmentRequest;
use App\Http\Requests\Api\V1\UpdateDepartmentRequest;
use App\Http\Resources\Api\V1\DepartmentResource;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Department;
use App\Models\Organization;
use App\Services\Auditing\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class DepartmentController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('view', $organization);

        $viewerMembership = request()->user()?->organizationMemberships()
            ->where('organization_id', $organization->id)
            ->where('status', MembershipStatus::Active->value)
            ->first();

        return DepartmentResource::collection(
            $organization->departments()
                ->when(
                    $viewerMembership?->role === MembershipRole::Supervisor,
                    fn ($query) => $query->whereKey($viewerMembership->department_id ?? 0),
                )
                ->withCount('memberships')->orderBy('name')->get(),
        );
    }

    public function store(StoreDepartmentRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('update', $organization);

        $department = $organization->departments()->create([
            'name' => $request->string('name')->toString(),
            'slug' => $request->filled('slug')
                ? $request->string('slug')->toString()
                : Str::slug($request->string('name')->toString()),
            'description' => $request->input('description'),
            'color' => $request->input('color'),
        ]);
        $this->auditLogger->record($request, $request->user(), $organization, 'department.created', $department);

        return DepartmentResource::make($department)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateDepartmentRequest $request,
        Organization $organization,
        Department $department,
    ): DepartmentResource {
        Gate::authorize('update', $organization);

        $before = $department->only(['name', 'slug', 'description', 'color']);
        $department->update($request->validated());
        $this->auditLogger->record($request, $request->user(), $organization, 'department.updated', $department, $before, $department->fresh()->only(['name', 'slug', 'description', 'color']));

        return DepartmentResource::make($department->refresh());
    }
}
