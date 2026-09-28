<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipRole;
use App\Models\AiCreditPurchase;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Ai\AiCreditService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiCreditWalletControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_owner_can_request_a_top_up_and_balance_is_unchanged_until_confirmed(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/organizations/{$organization->public_id}/ai-credit-wallet/purchases", [
            'amount_minor' => 500000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'pending');
        $this->assertSame(100, $response->json('data.units'));

        $show = $this->getJson("/api/v1/organizations/{$organization->public_id}/ai-credit-wallet");
        $show->assertOk()->assertJsonPath('data.balance_units', 0);
    }

    public function test_member_cannot_request_a_top_up(): void
    {
        [$member, $organization] = $this->organizationWithUser(MembershipRole::Member);
        Sanctum::actingAs($member);

        $this->postJson("/api/v1/organizations/{$organization->public_id}/ai-credit-wallet/purchases", ['amount_minor' => 500000])
            ->assertForbidden();
    }

    public function test_super_admin_can_complete_a_purchase_and_balance_increases(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        $admin = User::factory()->create(['is_super_admin' => true]);
        Sanctum::actingAs($owner);
        $credits = app(AiCreditService::class);
        $purchase = $credits->requestPurchase($organization, $owner, 500000);

        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/v1/super-admin/ai-credits/purchases/{$purchase->public_id}/complete");

        $response->assertOk();
        $this->assertSame('completed', AiCreditPurchase::query()->find($purchase->id)->status);

        Sanctum::actingAs($owner);
        $balance = $this->getJson("/api/v1/organizations/{$organization->public_id}/ai-credit-wallet");
        $balance->assertOk()->assertJsonPath('data.balance_units', 100);
    }

    private function organizationWithUser(MembershipRole $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($user)->create([
            'role' => $role,
        ]);

        return [$user, $organization];
    }
}
