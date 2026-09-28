<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Ai\AiCreditService;
use App\Services\Auditing\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Org-facing view of the org's own AI credit wallet, and requesting a
 * top-up. v1 is admin-confirmed payment only (see AiCreditService's
 * docblock) - no gateway/checkout branch here, unlike SmsWalletController.
 */
class AiCreditWalletController extends Controller
{
    public function __construct(
        private readonly AiCreditService $credits,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function show(Organization $organization): JsonResponse
    {
        Gate::authorize('manageAiCredits', $organization);

        return response()->json(['data' => $this->data($organization)]);
    }

    public function requestPurchase(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manageAiCredits', $organization);
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000'],
        ]);

        $purchase = $this->credits->requestPurchase($organization, $request->user(), $data['amount_minor']);
        $this->auditLogger->record(
            $request,
            $request->user(),
            $organization,
            'ai_credit.purchase_requested',
            $purchase,
            null,
            ['amount_minor' => $purchase->amount_minor, 'units' => $purchase->units],
        );

        return response()->json([
            'data' => $this->purchaseData($purchase),
            'message' => 'Top-up request created. NetReverb must confirm payment before minutes are credited.',
        ], 201);
    }

    private function data(Organization $organization): array
    {
        $wallet = $this->credits->wallet($organization);
        $pricing = $this->credits->pricing();

        return [
            'balance_units' => $wallet->balance_units,
            'currency' => $pricing->currency,
            'selling_per_unit_minor' => $pricing->selling_per_unit_minor,
            'minimum_purchase_minor' => $pricing->minimum_purchase_minor,
            'purchases' => $organization->aiCreditPurchases()
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn ($purchase) => $this->purchaseData($purchase)),
            'transactions' => $wallet->transactions()
                ->latest('created_at')
                ->limit(30)
                ->get()
                ->map(fn ($transaction) => $transaction->only([
                    'public_id', 'type', 'units', 'balance_after', 'description', 'created_at',
                ])),
        ];
    }

    private function purchaseData($purchase): array
    {
        return $purchase->only([
            'public_id', 'reference', 'currency', 'amount_minor', 'units',
            'selling_per_unit_minor', 'status', 'payment_method',
            'created_at', 'completed_at',
        ]);
    }
}
