<?php

namespace Tests\Feature;

use App\Exceptions\Messaging\InsufficientSmsCreditException;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\OutboundMessage;
use App\Models\SmsWalletTransaction;
use App\Models\User;
use App\Services\Messaging\SmsCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsCreditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_completion_credits_the_wallet_exactly_once(): void
    {
        [$organization, $user] = $this->tenant();
        $admin = User::factory()->create(['is_super_admin' => true]);
        $credits = app(SmsCreditService::class);

        $purchase = $credits->requestPurchase($organization, $user, 500_000);

        $this->assertSame(1_000, $purchase->units);
        $this->assertSame(300_000, $purchase->profit_minor);

        $credits->completePurchase($purchase, $admin, 'MANUAL-001');
        $credits->completePurchase($purchase->refresh(), $admin, 'MANUAL-001');

        $this->assertSame(1_000, $credits->wallet($organization)->refresh()->balance_units);
        $this->assertDatabaseCount('sms_wallet_transactions', 1);
        $this->assertDatabaseHas('sms_credit_purchases', [
            'id' => $purchase->id,
            'status' => 'completed',
            'payment_reference' => 'MANUAL-001',
        ]);
    }

    public function test_message_debit_and_refund_are_idempotent(): void
    {
        [$organization, $user] = $this->tenant();
        $admin = User::factory()->create(['is_super_admin' => true]);
        $credits = app(SmsCreditService::class);
        $purchase = $credits->requestPurchase($organization, $user, 500_000);
        $credits->completePurchase($purchase, $admin);
        $message = $this->message($organization, $user, str_repeat('a', 161));

        $credits->debitForMessage($message);
        $credits->debitForMessage($message->refresh());

        $this->assertSame(998, $credits->wallet($organization)->refresh()->balance_units);
        $this->assertSame(2, $message->refresh()->sms_units);
        $this->assertSame('debited', $message->billing_status);
        $this->assertSame(
            1,
            SmsWalletTransaction::query()->where('type', 'message_debit')->count(),
        );

        $credits->refundMessage($message, 'Provider rejected the message.');
        $credits->refundMessage($message->refresh(), 'Duplicate refund attempt.');

        $this->assertSame(1_000, $credits->wallet($organization)->refresh()->balance_units);
        $this->assertSame('refunded', $message->refresh()->billing_status);
        $this->assertSame(
            1,
            SmsWalletTransaction::query()->where('type', 'message_refund')->count(),
        );
    }

    public function test_insufficient_credit_does_not_create_a_debit(): void
    {
        [$organization, $user] = $this->tenant();
        $credits = app(SmsCreditService::class);
        $message = $this->message($organization, $user, 'This requires one SMS unit.');

        try {
            $credits->debitForMessage($message);
            $this->fail('An insufficient-credit exception should have been thrown.');
        } catch (InsufficientSmsCreditException) {
            $this->assertSame(0, $credits->wallet($organization)->refresh()->balance_units);
            $this->assertDatabaseCount('sms_wallet_transactions', 0);
            $this->assertNull($message->refresh()->billing_status);
        }
    }

    /** @return array{Organization, User} */
    private function tenant(): array
    {
        return [Organization::factory()->create(), User::factory()->create()];
    }

    private function message(Organization $organization, User $user, string $body): OutboundMessage
    {
        $lead = Lead::query()->create([
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'name' => 'Test Recipient',
            'phone' => '+2348000000000',
        ]);

        return OutboundMessage::query()->create([
            'organization_id' => $organization->id,
            'lead_id' => $lead->id,
            'created_by_user_id' => $user->id,
            'channel' => 'sms',
            'destination' => '+2348000000000',
            'body' => $body,
            'status' => 'approved',
        ]);
    }
}
