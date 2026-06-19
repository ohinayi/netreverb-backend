<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ServiceNumbers\CreateServiceNumber;
use App\Actions\ServiceNumbers\DeleteServiceNumber;
use App\Actions\ServiceNumbers\UpdateServiceNumber;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreServiceNumberRequest;
use App\Http\Requests\Api\V1\UpdateServiceNumberRequest;
use App\Http\Resources\Api\V1\ServiceNumberResource;
use App\Models\Organization;
use App\Models\ServiceNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ServiceNumberController extends Controller
{
    public function __construct(
        private CreateServiceNumber $createServiceNumber,
        private UpdateServiceNumber $updateServiceNumber,
        private DeleteServiceNumber $deleteServiceNumber,
    ) {}

    public function index(Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ServiceNumber::class, $organization]);
        $serviceNumbers = $organization->serviceNumbers()
            ->with('dialableNumber')
            ->latest()
            ->paginate(25);

        return ServiceNumberResource::collection($serviceNumbers);
    }

    public function store(
        StoreServiceNumberRequest $request,
        Organization $organization,
    ): JsonResponse {
        Gate::authorize('create', [ServiceNumber::class, $organization]);

        return ServiceNumberResource::make(
            $this->createServiceNumber->execute($organization, $request->validated()),
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(
        Organization $organization,
        ServiceNumber $serviceNumber,
    ): ServiceNumberResource {
        Gate::authorize('view', $serviceNumber);

        return ServiceNumberResource::make($serviceNumber->load('dialableNumber'));
    }

    public function update(
        UpdateServiceNumberRequest $request,
        Organization $organization,
        ServiceNumber $serviceNumber,
    ): ServiceNumberResource {
        Gate::authorize('update', $serviceNumber);

        return ServiceNumberResource::make(
            $this->updateServiceNumber->execute($serviceNumber, $request->validated()),
        );
    }

    public function destroy(
        Organization $organization,
        ServiceNumber $serviceNumber,
    ): Response {
        Gate::authorize('delete', $serviceNumber);
        $this->deleteServiceNumber->execute($serviceNumber);

        return response()->noContent();
    }
}
