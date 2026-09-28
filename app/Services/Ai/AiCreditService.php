<?php

namespace App\Services\Ai;

use App\Models\AiAssistantSession;
use App\Models\AiCreditPurchase;
use App\Models\AiCreditWallet;
use App\Models\AiCreditWalletTransaction;
use App\Models\AiPricingSetting;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Prepaid credit for speech-to-speech (Gemini Live) AI calls - mirrors
 * SmsCreditService's wallet/ledger shape, with one real structural
 * difference: SMS debits BEFORE sending and can block the send outright;
 * a phone call's real duration/cost isn't known until it ends, so this
 * only offers a cheap pre-flight "does this org have any balance at all"
 * check (hasSufficientBalance) before a call starts, and debits for the
 * ACTUAL duration afterward (debitForSession) - which is allowed to take
 * the wallet negative, the same way prepaid mobile airtime can dip
 * slightly negative on the last call before a top-up.
 *
 * v1 is admin-confirmed payment only (no Paystack/Flutterwave) -
 * PaymentGatewayService/ProcessPaymentWebhook are hard-wired to
 * SmsCreditPurchase today and generalizing that shared, real-money
 * pipeline is a deliberately separate, later piece of work.
 */
class AiCreditService
{
    public function pricing(): AiPricingSetting
    {
        return AiPricingSetting::query()->firstOrCreate(
            ['provider' => 'gemini_live'],
            [
                'currency' => 'NGN',
                'cost_per_unit_minor' => (int) config('ai.credit.cost_per_unit_minor', 3100),
                'selling_per_unit_minor' => (int) config('ai.credit.selling_per_unit_minor', 5000),
                'minimum_purchase_minor' => (int) config('ai.credit.minimum_purchase_minor', 500000),
            ],
        );
    }

    public function wallet(Organization $organization): AiCreditWallet
    {
        return AiCreditWallet::query()->firstOrCreate(
            ['organization_id' => $organization->id],
            ['balance_units' => 0],
        );
    }

    public function hasSufficientBalance(Organization $organization): bool
    {
        return $this->wallet($organization)->balance_units >= 1;
    }

    public function requestPurchase(Organization $organization, User $user, int $amountMinor): AiCreditPurchase
    {
        $pricing = $this->pricing();
        if ($amountMinor < $pricing->minimum_purchase_minor) {
            throw new RuntimeException('The requested top-up is below the platform minimum.');
        }

        $units = intdiv($amountMinor, $pricing->selling_per_unit_minor);
        if ($units < 1) {
            throw new RuntimeException('The requested amount does not purchase any AI minutes.');
        }

        return AiCreditPurchase::query()->create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $user->id,
            'reference' => 'AI-'.Str::upper((string) Str::ulid()),
            'payment_method' => 'admin',
            'currency' => $pricing->currency,
            'amount_minor' => $amountMinor,
            'units' => $units,
            'cost_per_unit_minor' => $pricing->cost_per_unit_minor,
            'selling_per_unit_minor' => $pricing->selling_per_unit_minor,
            'profit_minor' => max(0, ($pricing->selling_per_unit_minor - $pricing->cost_per_unit_minor) * $units),
            'status' => 'pending',
        ]);
    }

    public function completePurchase(
        AiCreditPurchase $purchase,
        ?User $admin,
        ?string $paymentReference = null,
    ): AiCreditPurchase {
        return DB::transaction(function () use ($purchase, $admin, $paymentReference): AiCreditPurchase {
            $lockedPurchase = AiCreditPurchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if ($lockedPurchase->status === 'completed') {
                return $lockedPurchase;
            }
            if ($lockedPurchase->status !== 'pending') {
                throw new RuntimeException('Only a pending AI credit purchase can be completed.');
            }

            $wallet = AiCreditWallet::query()->firstOrCreate(
                ['organization_id' => $lockedPurchase->organization_id],
                ['balance_units' => 0],
            );
            $wallet = AiCreditWallet::query()->lockForUpdate()->findOrFail($wallet->id);
            $balance = $wallet->balance_units + $lockedPurchase->units;
            $wallet->update(['balance_units' => $balance]);

            AiCreditWalletTransaction::query()->firstOrCreate(
                ['idempotency_key' => "purchase:{$lockedPurchase->public_id}"],
                [
                    'ai_credit_wallet_id' => $wallet->id,
                    'ai_credit_purchase_id' => $lockedPurchase->id,
                    'created_by_user_id' => $admin?->id,
                    'type' => 'purchase_credit',
                    'units' => $lockedPurchase->units,
                    'balance_after' => $balance,
                    'description' => 'AI credit purchase completed by NetReverb.',
                ],
            );
            $lockedPurchase->update([
                'status' => 'completed',
                'completed_by_user_id' => $admin?->id,
                'payment_reference' => $paymentReference,
                'completed_at' => now(),
            ]);

            return $lockedPurchase->refresh();
        });
    }

    /**
     * Debits for a finished (or timed-out) speech-to-speech session. Never
     * throws on insufficient balance - the call already happened and must
     * be billed regardless of what that does to the balance; the wallet
     * going negative is what then blocks the NEXT call via
     * hasSufficientBalance(). Idempotent - safe to call more than once for
     * the same session (e.g. if a stale-session sweep and a late-arriving
     * bridge completion callback both fire for the same call).
     */
    public function debitForSession(AiAssistantSession $session, ?int $overrideDurationSeconds = null): ?AiCreditWalletTransaction
    {
        $durationSeconds = $overrideDurationSeconds ?? $session->duration_seconds ?? 0;
        $units = max(1, (int) ceil($durationSeconds / 60));

        return DB::transaction(function () use ($session, $units): AiCreditWalletTransaction {
            $key = "ai-session-debit:{$session->public_id}";
            $existing = AiCreditWalletTransaction::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }

            $wallet = AiCreditWallet::query()->firstOrCreate(
                ['organization_id' => $session->organization_id],
                ['balance_units' => 0],
            );
            $wallet = AiCreditWallet::query()->lockForUpdate()->findOrFail($wallet->id);
            $balance = $wallet->balance_units - $units;
            $wallet->update(['balance_units' => $balance]);

            return AiCreditWalletTransaction::query()->create([
                'ai_credit_wallet_id' => $wallet->id,
                'ai_assistant_session_id' => $session->id,
                'idempotency_key' => $key,
                'type' => 'session_debit',
                'units' => -$units,
                'balance_after' => $balance,
                'description' => "AI call - {$units} minute(s) billed.",
            ]);
        });
    }
}
