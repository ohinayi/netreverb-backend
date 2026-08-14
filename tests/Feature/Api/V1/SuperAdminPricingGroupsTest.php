<?php

namespace Tests\Feature\Api\V1;

use App\Models\Organization;
use App\Models\PricingGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminPricingGroupsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_super_admin_can_create_list_update_and_delete_a_pricing_group(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

        $created = $this->postJson('/api/v1/super-admin/pricing-groups', [
            'name' => 'Growth',
            'slug' => 'growth',
            'applies_to' => 'organization',
            'price_minor' => 4900,
            'currency' => 'USD',
            'billing_interval' => 'monthly',
            'features' => ['sip_calling', 'conference_rooms'],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Growth')
            ->assertJsonPath('data.applies_to', 'organization')
            ->assertJsonPath('data.features', ['sip_calling', 'conference_rooms']);

        $publicId = $created->json('data.public_id');

        $this->getJson('/api/v1/super-admin/pricing-groups')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'growth')
            ->assertJsonPath('data.0.organizations_count', 0)
            ->assertJsonStructure(['feature_catalog']);

        $this->patchJson("/api/v1/super-admin/pricing-groups/{$publicId}", [
            'name' => 'Growth',
            'slug' => 'growth',
            'applies_to' => 'organization',
            'price_minor' => 5900,
            'currency' => 'USD',
            'billing_interval' => 'monthly',
            'features' => ['sip_calling'],
        ])->assertOk()->assertJsonPath('data.price_minor', 5900);

        $this->deleteJson("/api/v1/super-admin/pricing-groups/{$publicId}")
            ->assertNoContent();
    }

    public function test_cannot_delete_a_pricing_group_with_organizations_assigned(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

        $group = PricingGroup::factory()->create();
        Organization::factory()->create(['pricing_group_id' => $group->id]);

        $this->deleteJson("/api/v1/super-admin/pricing-groups/{$group->public_id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pricing_group');
    }

    public function test_super_admin_can_assign_a_pricing_group_to_an_organization(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

        $group = PricingGroup::factory()->create();
        $organization = Organization::factory()->create();

        $this->patchJson("/api/v1/super-admin/organizations/{$organization->public_id}/pricing-group", [
            'pricing_group_id' => $group->public_id,
        ])->assertOk()->assertJsonPath('data.pricing_group.public_id', $group->public_id);

        $this->patchJson("/api/v1/super-admin/organizations/{$organization->public_id}/pricing-group", [
            'pricing_group_id' => null,
        ])->assertOk()->assertJsonPath('data.pricing_group', null);
    }

    public function test_regular_user_cannot_manage_pricing_groups(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/super-admin/pricing-groups')->assertForbidden();
        $this->postJson('/api/v1/super-admin/pricing-groups', [])->assertForbidden();
    }

    public function test_organization_keeps_full_access_until_billing_is_activated(): void
    {
        $organization = Organization::factory()->create();

        $this->assertTrue($organization->hasFeature('call_recording'));
        $this->assertNotEmpty($organization->availableFeatures());
    }

    public function test_activating_billing_without_confirmed_payment_hides_every_feature(): void
    {
        $organization = Organization::factory()->create(['payment_required' => true]);

        $this->assertFalse($organization->hasFeature('call_recording'));
        $this->assertSame([], $organization->availableFeatures());
    }

    public function test_confirmed_payment_grants_only_the_assigned_plans_features(): void
    {
        $group = PricingGroup::factory()->create(['features' => ['call_recording']]);
        $organization = Organization::factory()->create([
            'pricing_group_id' => $group->id,
            'payment_required' => true,
            'payment_confirmed' => true,
        ]);

        $this->assertTrue($organization->hasFeature('call_recording'));
        $this->assertFalse($organization->hasFeature('conference_rooms'));
        $this->assertSame(['call_recording'], $organization->availableFeatures());
    }

    public function test_super_admin_can_activate_and_confirm_billing_for_an_organization(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

        $organization = Organization::factory()->create();

        $this->patchJson("/api/v1/super-admin/organizations/{$organization->public_id}/billing", [
            'payment_required' => true,
            'payment_confirmed' => false,
        ])->assertOk()
            ->assertJsonPath('data.payment_required', true)
            ->assertJsonPath('data.payment_confirmed', false);

        $this->patchJson("/api/v1/super-admin/organizations/{$organization->public_id}/billing", [
            'payment_required' => true,
            'payment_confirmed' => true,
        ])->assertOk()->assertJsonPath('data.payment_confirmed', true);
    }
}
