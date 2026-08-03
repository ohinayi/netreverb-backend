<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class WorkspaceController extends Controller
{
    public function index(Organization $organization): JsonResource
    {
        Gate::authorize('view', $organization);

        return JsonResource::collection(
            $organization->workspaces()->withCount(['memberships', 'departments'])->latest()->paginate(20),
        );
    }

    public function store(Request $request, Organization $organization): JsonResource
    {
        Gate::authorize('update', $organization);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', 'alpha_dash'],
            'kind' => ['sometimes', 'string', 'in:team,personal'],
            'settings' => ['nullable', 'array'],
        ]);
        $data['slug'] = $data['slug'] ?? str($data['name'])->slug()->value();
        $workspace = $organization->workspaces()->create($data + ['status' => 'active']);

        return (new JsonResource($workspace->loadCount(['memberships', 'departments'])))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Organization $organization, Workspace $workspace): JsonResource
    {
        Gate::authorize('view', $organization);
        abort_unless($workspace->organization_id === $organization->id, Response::HTTP_NOT_FOUND);

        return new JsonResource($workspace->loadCount(['memberships', 'departments']));
    }

    public function update(Request $request, Organization $organization, Workspace $workspace): JsonResource
    {
        Gate::authorize('update', $organization);
        abort_unless($workspace->organization_id === $organization->id, Response::HTTP_NOT_FOUND);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:120', 'alpha_dash'],
            'status' => ['sometimes', 'string', 'in:active,archived'],
            'settings' => ['nullable', 'array'],
        ]);
        $workspace->update($data);

        return new JsonResource($workspace->refresh()->loadCount(['memberships', 'departments']));
    }

    public function destroy(Organization $organization, Workspace $workspace): Response
    {
        Gate::authorize('update', $organization);
        abort_unless($workspace->organization_id === $organization->id, Response::HTTP_NOT_FOUND);
        abort_if($organization->workspaces()->count() <= 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'An organization must retain one workspace.');
        $workspace->update(['status' => 'archived']);
        $workspace->delete();

        return response()->noContent();
    }
}
