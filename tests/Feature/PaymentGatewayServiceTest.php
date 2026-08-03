<?php

namespace Tests\Feature;

use App\Jobs\ProcessPaymentWebhook;
use App\Models\Organization;
use App\Models\PaymentWebhookEvent;
use App\Models\SmsCreditPurchase;
use App\Models\User;
use App\Services\Messaging\SmsCreditService;
use App\Services\Payments\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PaymentGatewayServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_paystack_checkout_is_initialized_on_the_server(): void
    {
        config()->set('payments.enabled', true);
        config()->set('payments.paystack.secret_key', 'paystack-test-secret');
        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.test/access',
                    'access_code' => 'access-code',
                ],
            ]),
        ]);
        [$purchase, $user] = $this->purchase('paystack');

        $checkout = app(PaymentGatewayService::class)
            ->initialize($purchase->load('organization'), $user->email);

        $this->assertSame('https://checkout.paystack.test/access', $checkout['checkout_url']);
        $this->assertSame('access-code', $checkout['access_code']);
        Http::assertSent(fn ($request) => $request['reference'] === $purchase->reference
            && $request['amount'] === 500_000
            && $request->hasHeader('Authorization', 'Bearer paystack-test-secret'));
    }

    public function test_verified_paystack_payment_credits_once(): void
    {
        config()->set('payments.paystack.secret_key', 'paystack-test-secret');
        [$purchase] = $this->purchase('paystack');
        Http::fake([
            "https://api.paystack.co/transaction/verify/{$purchase->reference}" => Http::response([
                'status' => true,
                'data' => [
                    'id' => 991,
                    'status' => 'success',
                    'reference' => $purchase->reference,
                    'amount' => 500_000,
                    'currency' => 'NGN',
                ],
            ]),
        ]);
        $payments = app(PaymentGatewayService::class);

        $payments->verifyAndComplete($purchase);
        $payments->verifyAndComplete($purchase->refresh());

        $this->assertSame('completed', $purchase->refresh()->status);
        $this->assertSame('paystack:991', $purchase->payment_reference);
        $this->assertSame(
            1_000,
            app(SmsCreditService::class)->wallet($purchase->organization)->refresh()->balance_units,
        );
        $this->assertDatabaseCount('sms_wallet_transactions', 1);
    }

    public function test_mismatched_payment_is_never_credited(): void
    {
        config()->set('payments.paystack.secret_key', 'paystack-test-secret');
        [$purchase] = $this->purchase('paystack');
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'id' => 992,
                    'status' => 'success',
                    'reference' => $purchase->reference,
                    'amount' => 100,
                    'currency' => 'NGN',
                ],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('amount does not match');

        try {
            app(PaymentGatewayService::class)->verifyAndComplete($purchase);
        } finally {
            $this->assertSame('pending', $purchase->refresh()->status);
            $this->assertDatabaseCount('sms_wallet_transactions', 0);
        }
    }

    public function test_flutterwave_verification_uses_major_currency_amount_and_credits_once(): void
    {
        config()->set('payments.flutterwave.secret_key', 'flutterwave-test-secret');
        [$purchase] = $this->purchase('flutterwave');
        Http::fake([
            'https://api.flutterwave.com/v3/transactions/771/verify' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 771,
                    'status' => 'successful',
                    'tx_ref' => $purchase->reference,
                    'amount' => 5000,
                    'currency' => 'NGN',
                ],
            ]),
        ]);

        app(PaymentGatewayService::class)->verifyAndComplete($purchase, '771');

        $this->assertSame('completed', $purchase->refresh()->status);
        $this->assertSame('flutterwave:771', $purchase->payment_reference);
        $this->assertSame(
            1_000,
            app(SmsCreditService::class)->wallet($purchase->organization)->refresh()->balance_units,
        );
    }

    public function test_flutterwave_webhook_uses_hmac_signature(): void
    {
        config()->set('payments.flutterwave.webhook_secret_hash', 'flutterwave-webhook-secret');
        $raw = json_encode([
            'type' => 'charge.completed',
            'data' => ['id' => 'charge-1', 'tx_ref' => 'SMS-REFERENCE'],
        ], JSON_THROW_ON_ERROR);
        $signature = base64_encode(
            hash_hmac('sha256', $raw, 'flutterwave-webhook-secret', true),
        );
        $request = Request::create(
            '/api/v1/payments/webhooks/flutterwave',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_FLUTTERWAVE_SIGNATURE' => $signature],
            $raw,
        );

        $this->assertTrue(
            app(PaymentGatewayService::class)->validWebhookSignature('flutterwave', $request),
        );
    }

    public function test_paystack_webhook_requires_a_valid_signature_and_queues_once(): void
    {
        config()->set('payments.paystack.secret_key', 'paystack-test-secret');
        Bus::fake();
        $payload = json_encode([
            'event' => 'charge.success',
            'data' => ['id' => 993, 'reference' => 'SMS-REFERENCE'],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/v1/payments/webhooks/paystack',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'invalid'],
            $payload,
        )->assertUnauthorized();

        $signature = hash_hmac('sha512', $payload, 'paystack-test-secret');
        foreach ([1, 2] as $attempt) {
            $this->call(
                'POST',
                '/api/v1/payments/webhooks/paystack',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature],
                $payload,
            )->assertOk();
        }

        $this->assertDatabaseCount('payment_webhook_events', 1);
        Bus::assertDispatchedTimes(ProcessPaymentWebhook::class, 1);
        $this->assertSame(
            'received',
            PaymentWebhookEvent::query()->firstOrFail()->status,
        );
    }

    /** @return array{SmsCreditPurchase, User} */
    private function purchase(string $paymentMethod): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $purchase = app(SmsCreditService::class)
            ->requestPurchase($organization, $user, 500_000, $paymentMethod);

        return [$purchase, $user];
    }
}
