<?php

namespace App\Console\Commands;

use App\Models\SmsCreditPurchase;
use App\Services\Payments\PaymentGatewayService;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePendingSmsPayments extends Command
{
    protected $signature = 'payments:reconcile-sms-credit {--limit=100}';

    protected $description = 'Verify recent pending gateway payments and credit successful SMS purchases';

    public function handle(PaymentGatewayService $payments): int
    {
        if (! $payments->enabled()) {
            $this->components->info('Automated payments are disabled.');

            return self::SUCCESS;
        }

        $completed = 0;
        $pending = 0;
        $failed = 0;
        SmsCreditPurchase::query()
            ->where('status', 'pending')
            ->whereIn('payment_method', ['paystack', 'flutterwave'])
            ->whereBetween('created_at', [now()->subHours(48), now()->subMinutes(2)])
            ->oldest()
            ->limit(max(1, min(500, (int) $this->option('limit'))))
            ->get()
            ->each(function (SmsCreditPurchase $purchase) use (
                $payments,
                &$completed,
                &$pending,
                &$failed,
            ): void {
                try {
                    $payments->verifyAndComplete(
                        $purchase,
                        data_get($purchase->metadata, 'provider_transaction_id'),
                    );
                    $completed++;
                } catch (Throwable $exception) {
                    $pending++;
                    $purchase->update([
                        'metadata' => [
                            ...($purchase->metadata ?? []),
                            'last_reconciled_at' => now()->toIso8601String(),
                            'last_reconciliation_result' => str($exception->getMessage())->limit(500),
                        ],
                    ]);
                    if (! str_contains($exception->getMessage(), 'not been verified')) {
                        $failed++;
                    }
                }
            });

        $this->components->info(
            "Payment reconciliation complete: {$completed} credited, {$pending} still pending, {$failed} need review.",
        );

        return self::SUCCESS;
    }
}
