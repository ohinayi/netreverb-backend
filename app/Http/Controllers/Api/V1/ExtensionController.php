<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Extensions\CreateExtension;
use App\Actions\Extensions\DeleteExtension;
use App\Actions\Extensions\UpdateExtension;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreExtensionRequest;
use App\Http\Requests\Api\V1\UpdateExtensionRequest;
use App\Http\Resources\Api\V1\ExtensionResource;
use App\Models\Extension;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ExtensionController extends Controller
{
    public function __construct(
        private CreateExtension $createExtension,
        private UpdateExtension $updateExtension,
        private DeleteExtension $deleteExtension,
    ) {}

    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Extension::class, $organization]);

        $extensions = $organization->extensions()
            ->with(['dialableNumber', 'user', 'fallbackExtension', 'provisioningState'])
            ->when(
                Gate::denies('create', [Extension::class, $organization]),
                fn ($query) => $query->whereBelongsTo($request->user()),
            )
            ->latest()
            ->paginate(25);

        return ExtensionResource::collection($extensions);
    }

    public function store(
        StoreExtensionRequest $request,
        Organization $organization,
    ): JsonResponse {
        Gate::authorize('create', [Extension::class, $organization]);
        $result = $this->createExtension->execute($organization, $request->validated());

        $resource = ExtensionResource::make($result->extension);
        if ($result->sipPassword !== null) {
            $resource->additional(['meta' => ['sip_password' => $result->sipPassword, 'display_once' => true]]);
        }

        return $resource->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Organization $organization, Extension $extension): ExtensionResource
    {
        Gate::authorize('view', $extension);

        return ExtensionResource::make(
            $extension->load(['dialableNumber', 'user', 'fallbackExtension', 'provisioningState']),
        );
    }

    public function update(
        UpdateExtensionRequest $request,
        Organization $organization,
        Extension $extension,
    ): ExtensionResource {
        Gate::authorize('update', $extension);

        return ExtensionResource::make(
            $this->updateExtension->execute($extension, $request->validated()),
        );
    }

    public function destroy(Organization $organization, Extension $extension): Response
    {
        Gate::authorize('delete', $extension);
        $this->deleteExtension->execute($extension);

        return response()->noContent();
    }
}
