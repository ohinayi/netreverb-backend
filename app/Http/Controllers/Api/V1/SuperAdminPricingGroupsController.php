<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PricingGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SuperAdminPricingGroupsController extends Controller
{
    /**
     * The fixed set of modules a pricing group can gate. Kept here rather
     * than free-text so the admin UI can offer a checklist instead of
     * inventing feature keys that nothing in the app actually checks.
     */
    private const FEATURE_CATALOG = [
        'sip_calling' => 'SIP & WebRTC calling',
        'conference_rooms' => 'Conference rooms',
        'call_recording' => 'Call recording',
        'ai_assistants' => 'AI voice agents',
        'translation' => 'Real-time translation',
        'outbound_messaging' => 'Outbound messaging & campaigns',
        'sms_wallet' => 'SMS credits',
    ];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $groups = PricingGroup::query()
            ->withCount('organizations')
            ->orderBy('price_minor')
            ->get();

        return response()->json([
            'data' => $groups,
            'feature_catalog' => self::FEATURE_CATALOG,
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
            'price_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'billing_interval' => ['required', Rule::in(['monthly', 'annual'])],
            'features' => ['array'],
            'features.*' => [Rule::in(array_keys(self::FEATURE_CATALOG))],
            'is_active' => ['boolean'],
        ]);
    }
}
