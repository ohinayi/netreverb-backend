<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RingbackAdStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PricingGroup;
use App\Support\FeatureCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SuperAdminPricingGroupsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $groups = PricingGroup::query()
            ->withCount('organizations')
            ->orderBy('price_minor')
            ->get();

        return response()->json([
            'data' => $groups,
            'feature_catalog' => FeatureCatalog::MODULES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $data = $this->validated($request);

        $group = PricingGroup::query()->create($data);

        return response()->json(['data' => $group], 201);
    }

    public function update(Request $request, PricingGroup $pricingGroup): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $data = $this->validated($request, $pricingGroup);

        $pricingGroup->update($data);

        return response()->json(['data' => $pricingGroup->fresh()]);
    }

    public function destroy(Request $request, PricingGroup $pricingGroup): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        if ($pricingGroup->organizations()->exists()) {
            throw ValidationException::withMessages([
                'pricing_group' => 'Move every organization off this pricing group before deleting it.',
            ]);
        }

        $pricingGroup->delete();

        return response()->json(status: 204);
    }

    public function assignOrganization(Request $request, Organization $organization): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $validated = $request->validate([
            'pricing_group_id' => ['nullable', 'exists:pricing_groups,public_id'],
        ]);

        $pricingGroupId = $validated['pricing_group_id'] ?? null;

        $organization->update([
            'pricing_group_id' => $pricingGroupId
                ? PricingGroup::query()->where('public_id', $pricingGroupId)->value('id')
                : null,
        ]);

        return response()->json([
            'data' => $organization->fresh()->load('pricingGroup'),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PricingGroup $existing = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required', 'string', 'max:120', 'alpha_dash',
                Rule::unique('pricing_groups', 'slug')->ignore($existing?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'applies_to' => ['required', Rule::in(['individual', 'organization'])],
            'price_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'billing_interval' => ['required', Rule::in(['monthly', 'annual'])],
            'features' => ['array'],
            'features.*' => [Rule::in(FeatureCatalog::keys())],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * Toggle billing enforcement for one organization. Activating it without
     * also confirming payment immediately hides every gated feature - only
     * flip payment_required on once you're ready for that; confirm payment
     * separately once it's actually been collected.
     */
    public function updateBilling(Request $request, Organization $organization): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $validated = $request->validate([
            'payment_required' => ['required', 'boolean'],
            'payment_confirmed' => ['required', 'boolean'],
        ]);

        $organization->update($validated);

        return response()->json([
            'data' => $organization->fresh()->load('pricingGroup'),
        ]);
    }

    /**
     * A standalone toggle, deliberately separate from payment_required/
     * payment_confirmed above - this gates whether the org's own ringback
     * audio plays instead of the shared ad pool, not broader feature access.
     * A personal/individual workspace can never be exempt.
     */
    public function updateAdExemption(Request $request, Organization $organization): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
        abort_if($organization->isPersonalWorkspace(), 422);

        $validated = $request->validate([
            'ad_exempt' => ['required', 'boolean'],
        ]);

        $organization->update([
            ...$validated,
            // A direct manual override settles any open request one way or
            // the other, so the org sees a clean slate to ask again later.
            'ad_exemption_status' => $validated['ad_exempt'] ? RingbackAdStatus::Approved : null,
        ]);

        return response()->json(['data' => $organization->fresh()]);
    }

    /**
     * Approve or deny an org's self-service ad-exemption request. Denying
     * leaves ad_exempt untouched (it was already false to have a pending
     * request) but resets the status so the org can request again.
     */
    public function decideAdExemptionRequest(Request $request, Organization $organization): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
        abort_if($organization->ad_exemption_status !== RingbackAdStatus::Pending, 422, 'No pending request for this organization.');

        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
        ]);

        $organization->update($validated['approve']
            ? ['ad_exempt' => true, 'ad_exemption_status' => RingbackAdStatus::Approved]
            : ['ad_exemption_status' => RingbackAdStatus::Rejected]);

        return response()->json(['data' => $organization->fresh()]);
    }
}
