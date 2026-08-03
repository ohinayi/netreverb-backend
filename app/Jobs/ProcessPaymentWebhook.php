<?php

namespace App\Jobs;

use App\Models\PaymentWebhookEvent;
use App\Models\SmsCreditPurchase;
use App\Services\Payments\PaymentGatewayService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ProcessPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue('payments');
    }

    public function handle(PaymentGatewayService $payments): void
    {
        $event = PaymentWebhookEvent::query()->findOrFail($this->eventId);
        if ($event->status === 'processed') {
            return;
        }

        try {
            $metadata = $event->metadata ?? [];
            if (! $payments->isSuccessfulWebhook($event->provider, $metadata)) {
                $event->update(['status' => 'ignored', 'processed_at' => now()]);

                return;
            }

            $reference = $payments->webhookReference($event->provider, $metadata);
            throw_if(blank($reference), RuntimeException::class, 'The payment reference is missing.');
            $purchase = SmsCreditPurchase::query()
                ->where('reference', $reference)
                ->where('payment_method', $event->provider)
                ->firstOrFail();
            $event->update(['sms_credit_purchase_id' => $purchase->id, 'status' => 'processing']);
            $payments->verifyAndComplete(
                $purchase,
                $payments->webhookTransactionId($event->provider, $metadata),
            );
            $event->update([
                'status' => 'processed',
                'processing_error' => null,
                'processed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $event->update([
                'status' => 'failed',
                'processing_error' => str($exception->getMessage())->limit(2000),
            ]);

            throw $exception;
        }
    }
}
