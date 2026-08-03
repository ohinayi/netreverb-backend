<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\PaymentWebhookEvent;
use App\Services\Payments\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function __invoke(
        string $provider,
        Request $request,
        PaymentGatewayService $payments,
    ): JsonResponse {
        abort_unless(in_array($provider, ['paystack', 'flutterwave'], true), 404);
        abort_unless($payments->validWebhookSignature($provider, $request), 401, 'Invalid webhook signature.');

        $payload = $request->json()->all();
        $eventId = (string) (
            data_get($payload, 'id')
            ?? data_get($payload, 'data.id')
            ?? hash('sha256', $request->getContent())
        );
        $event = PaymentWebhookEvent::query()->firstOrCreate(
            ['provider' => $provider, 'provider_event_id' => $eventId],
            [
                'event_type' => data_get($payload, 'event') ?? data_get($payload, 'type'),
                'status' => 'received',
                'payload_hash' => hash('sha256', $request->getContent()),
                'metadata' => $this->safePayload($payload),
            ],
        );

        if ($event->wasRecentlyCreated || $event->status === 'failed') {
            ProcessPaymentWebhook::dispatch($event->id)->afterCommit();
        }

        return response()->json(['received' => true]);
    }

    /** @return array<string, mixed> */
    private function safePayload(array $payload): array
    {
        return array_filter([
            'id' => data_get($payload, 'id'),
            'event' => data_get($payload, 'event'),
            'type' => data_get($payload, 'type'),
            'data' => array_filter([
                'id' => data_get($payload, 'data.id'),
                'reference' => data_get($payload, 'data.reference'),
                'tx_ref' => data_get($payload, 'data.tx_ref'),
                'status' => data_get($payload, 'data.status'),
                'amount' => data_get($payload, 'data.amount'),
                'currency' => data_get($payload, 'data.currency'),
            ], fn ($value) => $value !== null),
        ], fn ($value) => $value !== null);
    }
}
