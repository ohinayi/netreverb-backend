<?php

namespace App\Services\Payments;

use App\Models\SmsCreditPurchase;
use App\Services\Messaging\SmsCreditService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaymentGatewayService
{
    public function __construct(private readonly SmsCreditService $credits) {}

    /** @return array<string, array{configured: bool, webhook_configured: bool}> */
    public function readiness(): array
    {
        return [
            'paystack' => [
                'configured' => filled(config('payments.paystack.secret_key')),
                'webhook_configured' => filled(config('payments.paystack.secret_key')),
            ],
            'flutterwave' => [
                'configured' => filled(config('payments.flutterwave.secret_key')),
                'webhook_configured' => filled(config('payments.flutterwave.webhook_secret_hash')),
            ],
        ];
    }

    public function enabled(): bool
    {
        return (bool) config('payments.enabled');
    }

    /** @return array{checkout_url: string, access_code: ?string} */
    public function initialize(SmsCreditPurchase $purchase, string $email): array
    {
        $this->assertUsable($purchase->payment_method);

        $result = match ($purchase->payment_method) {
            'paystack' => $this->initializePaystack($purchase, $email),
            'flutterwave' => $this->initializeFlutterwave($purchase, $email),
            default => throw new RuntimeException('This payment gateway is not supported.'),
        };

        $purchase->update([
            'metadata' => [
                ...($purchase->metadata ?? []),
                'checkout_url' => $result['checkout_url'],
                'access_code' => $result['access_code'],
                'initialized_at' => now()->toIso8601String(),
            ],
        ]);

        return $result;
    }

    public function verifyAndComplete(
        SmsCreditPurchase $purchase,
        ?string $providerTransactionId = null,
    ): SmsCreditPurchase {
        if ($purchase->status === 'completed') {
            return $purchase;
        }

        $verified = match ($purchase->payment_method) {
            'paystack' => $this->verifyPaystack($purchase),
            'flutterwave' => $this->verifyFlutterwave($purchase, $providerTransactionId),
            default => throw new RuntimeException('This purchase does not use an automated gateway.'),
        };

        if (! $verified['successful']) {
            throw new RuntimeException('The payment has not been verified as successful.');
        }
        if (strcasecmp(trim($verified['reference']), trim($purchase->reference)) !== 0) {
            throw new RuntimeException('The verified payment reference does not match this purchase.');
        }
        if ($verified['amount_minor'] !== $purchase->amount_minor) {
            throw new RuntimeException('The verified payment amount does not match this purchase.');
        }
        if (strcasecmp(trim($verified['currency']), trim($purchase->currency)) !== 0) {
            throw new RuntimeException('The verified payment currency does not match this purchase.');
        }

        $purchase->update([
            'metadata' => [
                ...($purchase->metadata ?? []),
                'provider_transaction_id' => $verified['transaction_id'],
                'verified_at' => now()->toIso8601String(),
            ],
        ]);

        return $this->credits->completePurchase(
            $purchase,
            null,
            "{$purchase->payment_method}:{$verified['transaction_id']}",
        );
    }

    public function validWebhookSignature(string $provider, Request $request): bool
    {
        $raw = $request->getContent();

        return match ($provider) {
            'paystack' => $this->secureEquals(
                hash_hmac('sha512', $raw, (string) config('payments.paystack.secret_key')),
                (string) $request->header('x-paystack-signature'),
            ),
            'flutterwave' => $this->validFlutterwaveSignature($request, $raw),
            default => false,
        };
    }

    public function webhookReference(string $provider, array $payload): ?string
    {
        return match ($provider) {
            'paystack' => data_get($payload, 'data.reference'),
            'flutterwave' => data_get($payload, 'data.tx_ref')
                ?? data_get($payload, 'data.reference'),
            default => null,
        };
    }

    public function webhookTransactionId(string $provider, array $payload): ?string
    {
        $value = match ($provider) {
            'paystack', 'flutterwave' => data_get($payload, 'data.id'),
            default => null,
        };

        return $value === null ? null : (string) $value;
    }

    public function isSuccessfulWebhook(string $provider, array $payload): bool
    {
        return match ($provider) {
            'paystack' => data_get($payload, 'event') === 'charge.success',
            'flutterwave' => in_array(
                data_get($payload, 'type') ?? data_get($payload, 'event'),
                ['charge.completed'],
                true,
            ) && in_array(data_get($payload, 'data.status'), ['successful', 'succeeded'], true),
            default => false,
        };
    }

    private function initializePaystack(SmsCreditPurchase $purchase, string $email): array
    {
        $response = $this->paystack()->post('/transaction/initialize', [
            'email' => $email,
            'amount' => $purchase->amount_minor,
            'currency' => $purchase->currency,
            'reference' => $purchase->reference,
            'callback_url' => $this->callbackUrl($purchase),
            'metadata' => [
                'sms_credit_purchase' => $purchase->public_id,
                'organization_id' => $purchase->organization?->public_id,
            ],
        ])->throw()->json();

        $url = data_get($response, 'data.authorization_url');
        throw_unless(is_string($url) && $url !== '', RuntimeException::class, 'Paystack did not return a checkout URL.');

        return [
            'checkout_url' => $url,
            'access_code' => data_get($response, 'data.access_code'),
        ];
    }

    private function initializeFlutterwave(SmsCreditPurchase $purchase, string $email): array
    {
        $response = $this->flutterwave()->post('/payments', [
            'tx_ref' => $purchase->reference,
            'amount' => number_format($purchase->amount_minor / 100, 2, '.', ''),
            'currency' => $purchase->currency,
            'redirect_url' => $this->callbackUrl($purchase),
            'customer' => ['email' => $email],
            'customizations' => [
                'title' => 'NetReverb SMS credit',
                'description' => "{$purchase->units} SMS units",
            ],
            'meta' => [
                'sms_credit_purchase' => $purchase->public_id,
                'organization_id' => $purchase->organization?->public_id,
            ],
        ])->throw()->json();

        $url = data_get($response, 'data.link');
        throw_unless(is_string($url) && $url !== '', RuntimeException::class, 'Flutterwave did not return a checkout URL.');

        return ['checkout_url' => $url, 'access_code' => null];
    }

    /** @return array{successful: bool, reference: string, amount_minor: int, currency: string, transaction_id: string} */
    private function verifyPaystack(SmsCreditPurchase $purchase): array
    {
        $data = $this->paystack()
            ->get('/transaction/verify/'.rawurlencode($purchase->reference))
            ->throw()
            ->json('data', []);

        return [
            'successful' => data_get($data, 'status') === 'success',
            'reference' => (string) data_get($data, 'reference', ''),
            'amount_minor' => (int) data_get($data, 'amount', -1),
            'currency' => (string) data_get($data, 'currency', ''),
            'transaction_id' => (string) data_get($data, 'id', ''),
        ];
    }

    /** @return array{successful: bool, reference: string, amount_minor: int, currency: string, transaction_id: string} */
    private function verifyFlutterwave(
        SmsCreditPurchase $purchase,
        ?string $providerTransactionId,
    ): array {
        // Flutterwave redirects include both transaction_id and tx_ref. Verify by
        // transaction id when available, but fall back to the immutable tx_ref so
        // a callback can still be reconciled when the redirect omits the id.
        $data = [];

        if (filled($providerTransactionId)) {
            try {
                $data = $this->flutterwave()
                    ->get('/transactions/'.rawurlencode($providerTransactionId).'/verify')
                    ->throw()
                    ->json('data', []);
            } catch (\Throwable $exception) {
                Log::warning('Flutterwave transaction-id verification failed; falling back to tx_ref.', [
                    'purchase' => $purchase->public_id,
                    'transaction_id' => $providerTransactionId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        // tx_ref is our immutable purchase reference and is the reliable fallback
        // when the provider redirect omits or returns a stale transaction id.
        if (! is_array($data) || blank(data_get($data, 'tx_ref'))) {
            $data = $this->flutterwave()
                ->get('/transactions/verify_by_reference', ['tx_ref' => $purchase->reference])
                ->throw()
                ->json('data', []);
        }

        return [
            'successful' => in_array(data_get($data, 'status'), ['successful', 'succeeded', 'completed'], true),
            'reference' => (string) (data_get($data, 'tx_ref') ?? data_get($data, 'reference', '')),
            'amount_minor' => (int) round(((float) data_get($data, 'amount', -1)) * 100),
            'currency' => (string) data_get($data, 'currency', ''),
            'transaction_id' => (string) data_get($data, 'id', $providerTransactionId),
        ];
    }

    private function assertUsable(string $gateway): void
    {
        throw_unless($this->enabled(), RuntimeException::class, 'Automated payments are disabled.');
        $readiness = $this->readiness();
        throw_unless(
            isset($readiness[$gateway])
            && $readiness[$gateway]['configured'],
            RuntimeException::class,
            'The selected payment gateway is not configured.',
        );
    }

    private function paystack(): PendingRequest
    {
        return Http::baseUrl((string) config('payments.paystack.base_url'))
            ->acceptJson()
            ->withToken((string) config('payments.paystack.secret_key'))
            ->timeout(12)
            ->connectTimeout(5);
    }

    private function flutterwave(): PendingRequest
    {
        return Http::baseUrl((string) config('payments.flutterwave.base_url'))
            ->acceptJson()
            ->withToken((string) config('payments.flutterwave.secret_key'))
            ->timeout(12)
            ->connectTimeout(5);
    }

    private function callbackUrl(SmsCreditPurchase $purchase): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            .'/app/outbound-messaging?'
            .http_build_query(['purchase' => $purchase->public_id]);
    }

    private function validFlutterwaveSignature(Request $request, string $raw): bool
    {
        $secret = (string) config('payments.flutterwave.webhook_secret_hash');
        if ($secret === '') {
            return false;
        }

        $signature = (string) $request->header('flutterwave-signature');
        if ($signature !== '') {
            return $this->secureEquals(
                base64_encode(hash_hmac('sha256', $raw, $secret, true)),
                $signature,
            );
        }

        return $this->secureEquals($secret, (string) $request->header('verif-hash'));
    }

    private function secureEquals(string $expected, string $actual): bool
    {
        return $expected !== '' && $actual !== '' && hash_equals($expected, $actual);
    }
}
