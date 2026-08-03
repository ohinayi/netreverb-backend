<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\SmsCreditPurchase;
use App\Services\Auditing\AuditLogger;
use App\Services\Messaging\SmsCreditService;
use App\Services\Payments\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuperAdminSmsController extends Controller
{
    public function __construct(
        private readonly SmsCreditService $credits,
        private readonly PaymentGatewayService $payments,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $pricing = $this->credits->pricing();
        $purchases = SmsCreditPurchase::query()
            ->with('organization:id,public_id,name')
            ->latest()
            ->paginate(30);

        return response()->json([
            'data' => [
                'pricing' => $pricing->only([
                    'currency', 'cost_per_unit_minor', 'selling_per_unit_minor',
                    'minimum_purchase_minor', 'updated_at',
                ]),
                'totals' => [
                    'revenue_minor' => (int) SmsCreditPurchase::query()->where('status', 'completed')->sum('amount_minor'),
                    'profit_minor' => (int) SmsCreditPurchase::query()->where('status', 'completed')->sum('profit_minor'),
                    'units_sold' => (int) SmsCreditPurchase::query()->where('status', 'completed')->sum('units'),
                    'pending_purchases' => SmsCreditPurchase::query()->where('status', 'pending')->count(),
                ],
                'payments' => [
                    'enabled' => $this->payments->enabled(),
                    'gateways' => $this->payments->readiness(),
                ],
                'purchases' => collect($purchases->items())->map(fn (SmsCreditPurchase $purchase) => [
                    ...$purchase->only([
                        'public_id', 'reference', 'payment_reference', 'payment_method', 'currency',
                        'amount_minor', 'units', 'cost_per_unit_minor', 'selling_per_unit_minor',
                        'profit_minor', 'status', 'created_at', 'completed_at',
                    ]),
                    'organization' => $purchase->organization?->only(['public_id', 'name']),
                ]),
                'meta' => [
                    'current_page' => $purchases->currentPage(),
                    'last_page' => $purchases->lastPage(),
                    'total' => $purchases->total(),
                ],
            ],
        ]);
    }

    public function updatePricing(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'cost_per_unit_minor' => ['required', 'integer', 'min:0', 'max:1000000'],
            'selling_per_unit_minor' => ['required', 'integer', 'min:1', 'max:1000000'],
            'minimum_purchase_minor' => ['required', 'integer', 'min:1', 'max:1000000000'],
        ]);
        abort_if(
            $data['selling_per_unit_minor'] < $data['cost_per_unit_minor'],
            422,
            'The selling price cannot be lower than the provider cost price.',
        );

        $pricing = $this->credits->pricing();
        $before = $pricing->only(array_keys($data));
        $pricing->update([...$data, 'updated_by_user_id' => $request->user()->id]);
        $this->auditLogger->record(
            $request,
            $request->user(),
            null,
            'platform.sms_pricing.updated',
            $pricing,
            $before,
            $data,
        );

        return response()->json(['data' => $pricing->fresh()]);
    }

    public function completePurchase(
        Request $request,
        SmsCreditPurchase $smsCreditPurchase,
    ): JsonResponse {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:160', 'unique:sms_credit_purchases,payment_reference'],
        ]);
        $purchase = $this->credits->completePurchase(
            $smsCreditPurchase,
            $request->user(),
            $data['payment_reference'] ?? null,
        );
        $organization = Organization::query()->findOrFail($purchase->organization_id);
        $this->auditLogger->record(
            $request,
            $request->user(),
            $organization,
            'sms_credit.purchase_completed',
            $purchase,
            ['status' => 'pending'],
            ['status' => 'completed', 'units' => $purchase->units],
        );

        return response()->json([
            'data' => $purchase,
            'message' => "{$purchase->units} SMS unit(s) credited.",
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
    }
}
