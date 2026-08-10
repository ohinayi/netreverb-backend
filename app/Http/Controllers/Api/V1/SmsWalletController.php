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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class SmsWalletController extends Controller
{
    public function __construct(
        private readonly SmsCreditService $credits,
        private readonly PaymentGatewayService $payments,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function show(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manageOutboundMessaging', $organization);

        return response()->json(['data' => $this->data($organization)]);
    }

    public function requestPurchase(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manageOutboundMessaging', $organization);
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'payment_method' => ['sometimes', 'string', 'in:admin,paystack,flutterwave'],
        ]);
        $paymentMethod = $data['payment_method'] ?? 'admin';
        if ($paymentMethod !== 'admin') {
            abort_unless($this->payments->enabled(), 422, 'Automated payments are disabled.');
            $gateway = $this->payments->readiness()[$paymentMethod] ?? null;
            abort_unless(
                $gateway && $gateway['configured'],
                422,
                'The selected payment gateway is not configured.',
            );
        }

        $purchase = $this->credits->requestPurchase(
            $organization,
            $request->user(),
            $data['amount_minor'],
            $paymentMethod,
        );
        $checkout = $paymentMethod === 'admin'
            ? null
            : $this->payments->initialize($purchase->load('organization'), $request->user()->email);
        $this->auditLogger->record(
            $request,
            $request->user(),
            $organization,
            'sms_credit.purchase_requested',
            $purchase,
            null,
            ['amount_minor' => $purchase->amount_minor, 'units' => $purchase->units],
        );

        return response()->json([
            'data' => $this->purchaseData($purchase),
            'checkout' => $checkout,
            'message' => $paymentMethod === 'admin'
                ? 'Top-up request created. NetReverb must confirm payment before units are credited.'
                : 'Secure checkout created. Units are credited only after provider verification.',
        ], 201);
    }

    public function verifyPurchase(
        Request $request,
        Organization $organization,
        SmsCreditPurchase $smsCreditPurchase,
    ): JsonResponse {
        Gate::authorize('manageOutboundMessaging', $organization);
        abort_unless($smsCreditPurchase->organization_id === $organization->id, 404);
        $data = $request->validate([
            'transaction_id' => ['nullable', 'string', 'max:160'],
        ]);
        abort_if($smsCreditPurchase->payment_method === 'admin', 422, 'Manual purchases cannot be verified through a gateway.');

        try {
            $purchase = $this->payments->verifyAndComplete(
                $smsCreditPurchase,
                $data['transaction_id'] ?? null,
            );
        } catch (\Throwable $exception) {
            Log::warning('SMS credit payment verification is pending.', [
                'organization' => $organization->public_id,
                'purchase' => $smsCreditPurchase->public_id,
                'gateway' => $smsCreditPurchase->payment_method,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'data' => $this->purchaseData($smsCreditPurchase->refresh()),
                'message' => $exception instanceof \RuntimeException
                    ? $exception->getMessage()
                    : 'The gateway has not returned a verifiable payment yet. The purchase remains pending; try again shortly.',
            ], 202);
        }

        return response()->json([
            'data' => $this->purchaseData($purchase),
            'message' => "{$purchase->units} SMS unit(s) credited.",
        ]);
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
            'purchase_mode' => $this->payments->enabled()
                ? 'manual_or_gateway'
                : 'admin_confirmation',
            'automated_payments_enabled' => $this->payments->enabled(),
            'payment_gateways' => $this->payments->readiness(),
            'purchases' => $organization->smsCreditPurchases()
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
            'selling_per_unit_minor', 'status', 'payment_method', 'payment_reference',
            'created_at', 'completed_at',
        ]);
    }
}
