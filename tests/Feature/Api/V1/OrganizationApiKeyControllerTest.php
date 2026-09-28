<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipRole;
use App\Models\Organization;
use App\Models\OrganizationApiKey;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationApiKeyControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_owner_can_create_a_key_and_the_plaintext_is_only_ever_returned_once(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/organizations/{$organization->public_id}/api-keys", [
            'name' => 'Acme CRM integration',
        ]);

        $response->assertCreated();
        $plaintext = $response->json('key');
        $this->assertStringStartsWith('nrb_live_', $plaintext);
        $response->assertJsonPath('data.name', 'Acme CRM integration');
        $response->assertJsonMissingPath('data.key_hash');

        $index = $this->getJson("/api/v1/organizations/{$organization->public_id}/api-keys");
        $index->assertOk();
        $this->assertArrayNotHasKey('key', $index->json('data.0') ?? []);
        $this->assertArrayNotHasKey('key_hash', $index->json('data.0') ?? []);
    }

    public function test_member_cannot_create_a_key(): void
    {
        [$member, $organization] = $this->organizationWithUser(MembershipRole::Member);
        Sanctum::actingAs($member);

        $this->postJson("/api/v1/organizations/{$organization->public_id}/api-keys", ['name' => 'x'])
            ->assertForbidden();
    }

    public function test_owner_can_revoke_a_key_and_it_immediately_fails_partner_auth(): void
    {
        [$owner, $organization] = $this->organizationWithUser(MembershipRole::Owner);
        Sanctum::actingAs($owner);

        $generated = OrganizationApiKey::generate($organization->id, 'To revoke', $owner->id);

        $this->getJson('/api/v1/partner/call-logs', ['Authorization' => 'Bearer '.$generated['plaintext']])
            ->assertOk();

        $this->deleteJson("/api/v1/organizations/{$organization->public_id}/api-keys/{$generated['model']->public_id}")
            ->assertOk();

        $this->getJson('/api/v1/partner/call-logs', ['Authorization' => 'Bearer '.$generated['plaintext']])
            ->assertUnauthorized();
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
