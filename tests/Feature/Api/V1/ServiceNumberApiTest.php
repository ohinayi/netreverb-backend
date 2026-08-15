<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipRole;
use App\Models\DialableNumber;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceNumberApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_create_a_configurable_service_number(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/service-numbers",
            [
                'number' => '459666',
                'name' => 'Echo test',
                'type' => 'echo',
                'target' => '9666',
                'enabled' => true,
            ],
        );

        $response->assertCreated()
            ->assertJsonPath('data.number', '459666')
            ->assertJsonPath('data.target', '9666');
        $this->assertModelExists(ServiceNumber::query()->sole());
    }

    public function test_editing_an_auto_activated_service_number_does_not_knock_it_back_to_pending(): void
    {
        config()->set('telephony.service_numbers.auto_activate', true);
        $admin = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        Sanctum::actingAs($admin);

        $created = $this->postJson("/api/v1/organizations/{$organization->public_id}/service-numbers", [
            'number' => '459667', 'name' => 'Assistant line', 'type' => 'assistant', 'target' => '9667',
        ])->assertCreated();
        $this->assertSame('active', $created->json('data.provisioning_status'));

        $edited = $this->patchJson("/api/v1/organizations/{$organization->public_id}/service-numbers/{$created->json('data.id')}", [
            'configuration' => ['ai_assistant_id' => 'some-assistant-id'],
        ])->assertOk();

        $this->assertSame('active', $edited->json('data.provisioning_status'));
    }

    public function test_editing_a_service_number_without_auto_activate_keeps_it_pending(): void
    {
        config()->set('telephony.service_numbers.auto_activate', false);
        $admin = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        Sanctum::actingAs($admin);

        $created = $this->postJson("/api/v1/organizations/{$organization->public_id}/service-numbers", [
            'number' => '459668', 'name' => 'Needs real provisioning', 'type' => 'custom', 'target' => '9668',
        ])->assertCreated();
        $this->assertSame('pending', $created->json('data.provisioning_status'));

        $edited = $this->patchJson("/api/v1/organizations/{$organization->public_id}/service-numbers/{$created->json('data.id')}", [
            'name' => 'Renamed',
        ])->assertOk();

        $this->assertSame('pending', $edited->json('data.provisioning_status'));
    }

    public function test_member_cannot_manage_service_numbers(): void
    {
        $member = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($member)->create([
            'role' => MembershipRole::Member,
        ]);
        Sanctum::actingAs($member);

        $this->postJson("/api/v1/organizations/{$organization->public_id}/service-numbers", [
            'number' => '45000',
            'name' => 'Conference',
            'type' => 'conference',
            'target' => '000',
        ])->assertForbidden();
    }

    public function test_service_number_cannot_reuse_an_extension_number(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->owner()->for($organization)->for($owner)->create();
        DialableNumber::factory()->for($organization)->create(['number' => '45101']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/organizations/{$organization->public_id}/service-numbers", [
            'number' => '45101',
            'name' => 'Collision',
            'type' => 'custom',
            'target' => '1000',
        ])->assertUnprocessable()->assertJsonValidationErrors('number');
    }

    public function test_service_number_cannot_use_the_conference_range(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->owner()->for($organization)->for($owner)->create();
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/organizations/{$organization->public_id}/service-numbers", [
            'number' => '45000000000',
            'name' => 'Conference collision',
            'type' => 'custom',
            'target' => '1000',
        ])->assertUnprocessable()->assertJsonValidationErrors('number');
    }
}
