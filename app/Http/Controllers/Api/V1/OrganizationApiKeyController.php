<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrganizationApiKeyResource;
use App\Models\Organization;
use App\Models\OrganizationApiKey;
use App\Services\Auditing\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Org-admin management of the org's own public-partner-API keys
 * (v1/partner/*, see AuthenticatePartnerApiKey). Normal session/Sanctum
 * auth - the admin is managing their own org via the web app, not a
 * partner request.
 */
class OrganizationApiKeyController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('manageApiKeys', $organization);

        return OrganizationApiKeyResource::collection(
            $organization->apiKeys()->latest()->get(),
        );
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manageApiKeys', $organization);

        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $generated = OrganizationApiKey::generate($organization->id, $data['name'], $request->user()?->id);

        $this->auditLogger->record(
            $request,
            $request->user(),
            $organization,
            'organization.api_key.created',
            $generated['model'],
        );

        return response()->json([
            'key' => $generated['plaintext'],
            'data' => OrganizationApiKeyResource::make($generated['model']),
        ], 201);
    }

    public function destroy(Request $request, Organization $organization, OrganizationApiKey $apiKey): JsonResponse
    {
        Gate::authorize('manageApiKeys', $organization);
        abort_unless($apiKey->organization_id === $organization->id, 404);

        $apiKey->forceFill(['revoked_at' => now()])->save();

        $this->auditLogger->record(
            $request,
            $request->user(),
            $organization,
            'organization.api_key.revoked',
            $apiKey,
        );

        return response()->json(['data' => OrganizationApiKeyResource::make($apiKey)]);
    }
}
