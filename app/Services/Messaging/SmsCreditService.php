<?php

namespace App\Services\Messaging;

use App\Exceptions\Messaging\InsufficientSmsCreditException;
use App\Models\Organization;
use App\Models\OutboundMessage;
use App\Models\SmsCreditPurchase;
use App\Models\SmsPricingSetting;
use App\Models\SmsWallet;
use App\Models\SmsWalletTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SmsCreditService
{
    public function __construct(private readonly SmsSegmentCalculator $segments) {}

    public function pricing(): SmsPricingSetting
    {
        return SmsPricingSetting::query()->firstOrCreate(
            ['provider' => 'ebulksms'],
            [
                'currency' => 'NGN',
                'cost_per_unit_minor' => (int) config('outbound.billing.cost_per_unit_minor', 200),
                'selling_per_unit_minor' => (int) config('outbound.billing.selling_per_unit_minor', 500),
                'minimum_purchase_minor' => (int) config('outbound.billing.minimum_purchase_minor', 500000),
            ],
        );
    }

    public function wallet(Organization $organization): SmsWallet
    {
        return SmsWallet::query()->firstOrCreate(
            ['organization_id' => $organization->id],
            ['balance_units' => 0],
        );
    }

    public function requestPurchase(
        Organization $organization,
        User $user,
        int $amountMinor,
        string $paymentMethod = 'admin',
    ): SmsCreditPurchase {
        $pricing = $this->pricing();
        if ($amountMinor < $pricing->minimum_purchase_minor) {
            throw new RuntimeException('The requested top-up is below the platform minimum.');
        }

        $units = intdiv($amountMinor, $pricing->selling_per_unit_minor);
        if ($units < 1) {
            throw new RuntimeException('The requested amount does not purchase any SMS units.');
        }

        return SmsCreditPurchase::query()->create([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $user->id,
            'reference' => 'SMS-'.Str::upper((string) Str::ulid()),
            'payment_method' => $paymentMethod,
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
        SmsCreditPurchase $purchase,
        ?User $admin,
        ?string $paymentReference = null,
    ): SmsCreditPurchase {
        return DB::transaction(function () use ($purchase, $admin, $paymentReference): SmsCreditPurchase {
            $lockedPurchase = SmsCreditPurchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if ($lockedPurchase->status === 'completed') {
                return $lockedPurchase;
            }
            if ($lockedPurchase->status !== 'pending') {
                throw new RuntimeException('Only a pending SMS credit purchase can be completed.');
            }

            $wallet = SmsWallet::query()->firstOrCreate(
                ['organization_id' => $lockedPurchase->organization_id],
                ['balance_units' => 0],
            );
            $wallet = SmsWallet::query()->lockForUpdate()->findOrFail($wallet->id);
            $balance = $wallet->balance_units + $lockedPurchase->units;
            $wallet->update(['balance_units' => $balance]);

            SmsWalletTransaction::query()->firstOrCreate(
                ['idempotency_key' => "purchase:{$lockedPurchase->public_id}"],
                [
                    'sms_wallet_id' => $wallet->id,
                    'sms_credit_purchase_id' => $lockedPurchase->id,
                    'created_by_user_id' => $admin?->id,
                    'type' => 'purchase_credit',
                    'units' => $lockedPurchase->units,
                    'balance_after' => $balance,
                    'description' => $admin
                        ? 'SMS credit purchase completed by NetReverb.'
                        : 'SMS credit purchase completed after verified gateway payment.',
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

    public function debitForMessage(OutboundMessage $message): ?SmsWalletTransaction
    {
        if ($message->channel !== 'sms') {
            return null;
        }

        $units = $this->segments->units($message->body);

        return DB::transaction(function () use ($message, $units): SmsWalletTransaction {
            $key = "message-debit:{$message->public_id}";
            $existing = SmsWalletTransaction::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }

            $wallet = SmsWallet::query()->firstOrCreate(
                ['organization_id' => $message->organization_id],
                ['balance_units' => 0],
            );
            $wallet = SmsWallet::query()->lockForUpdate()->findOrFail($wallet->id);
            if ($wallet->balance_units < $units) {
                throw new InsufficientSmsCreditException(
                    "Insufficient SMS credit. {$units} unit(s) required; {$wallet->balance_units} available.",
                );
            }

            $balance = $wallet->balance_units - $units;
            $wallet->update(['balance_units' => $balance]);
            $transaction = SmsWalletTransaction::query()->create([
                'sms_wallet_id' => $wallet->id,
                'outbound_message_id' => $message->id,
                'idempotency_key' => $key,
                'type' => 'message_debit',
                'units' => -$units,
                'balance_after' => $balance,
                'description' => 'SMS units reserved before provider dispatch.',
            ]);
            $message->update(['sms_units' => $units, 'billing_status' => 'debited']);

            return $transaction;
        });
    }

    public function refundMessage(OutboundMessage $message, string $reason): void
    {
        if ($message->channel !== 'sms') {
            return;
        }

        DB::transaction(function () use ($message, $reason): void {
            $debit = SmsWalletTransaction::query()
                ->where('idempotency_key', "message-debit:{$message->public_id}")
                ->first();
            if (! $debit || SmsWalletTransaction::query()
                ->where('idempotency_key', "message-refund:{$message->public_id}")
                ->exists()) {
                return;
            }

            $wallet = SmsWallet::query()->lockForUpdate()->findOrFail($debit->sms_wallet_id);
            $units = abs($debit->units);
            $balance = $wallet->balance_units + $units;
            $wallet->update(['balance_units' => $balance]);
            SmsWalletTransaction::query()->create([
                'sms_wallet_id' => $wallet->id,
                'outbound_message_id' => $message->id,
                'idempotency_key' => "message-refund:{$message->public_id}",
                'type' => 'message_refund',
                'units' => $units,
                'balance_after' => $balance,
                'description' => $reason,
            ]);
            $message->update(['billing_status' => 'refunded']);
        });
    }
}
