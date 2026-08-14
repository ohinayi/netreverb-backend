<?php

namespace Tests\Feature\Api\V1;

use App\Models\Organization;
use App\Models\OrganizationIvr;
use App\Models\OrganizationMembership;
use App\Models\ServiceNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationIvrApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actingAsAdmin(Organization $organization): void
    {
        $admin = User::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        Sanctum::actingAs($admin);
    }

    public function test_admin_can_create_an_ivr_with_a_submenu_and_a_directive_option(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsAdmin($organization);
        $service = ServiceNumber::factory()->for($organization)->create();
        $child = OrganizationIvr::create([
            'organization_id' => $organization->id, 'name' => 'Dressing help', 'enabled' => true,
        ]);

        $response = $this->postJson("/api/v1/organizations/{$organization->public_id}/ivrs", [
            'service_number_id' => $service->public_id,
            'name' => 'Main menu',
            'welcome_text' => 'Welcome. Press 1 for dressing issues.',
            'options' => [
                ['digit' => '1', 'label' => 'Dressing issues', 'destination_type' => 'submenu', 'destination' => $child->public_id, 'sort_order' => 0],
                ['digit' => '2', 'label' => 'Store hours', 'destination_type' => 'directive', 'directive_text' => 'We are open nine to five, Monday through Friday.', 'sort_order' => 1],
            ],
        ]);

        $response->assertCreated();
        $options = $response->json('data.options');
        $this->assertSame('submenu', $options[0]['destination_type']);
        $this->assertSame($child->public_id, $options[0]['destination']);
        $this->assertSame('directive', $options[1]['destination_type']);
        $this->assertSame('We are open nine to five, Monday through Friday.', $options[1]['directive_text']);
    }

    public function test_a_submenu_option_must_reference_an_existing_ivr_in_the_same_organization(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsAdmin($organization);
        $service = ServiceNumber::factory()->for($organization)->create();

        $this->postJson("/api/v1/organizations/{$organization->public_id}/ivrs", [
            'service_number_id' => $service->public_id,
            'name' => 'Main menu',
            'options' => [
                ['digit' => '1', 'label' => 'Nowhere', 'destination_type' => 'submenu', 'destination' => 'does-not-exist', 'sort_order' => 0],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['options.0.destination']);
    }

    public function test_a_directive_option_requires_message_text(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsAdmin($organization);
        $service = ServiceNumber::factory()->for($organization)->create();

        $this->postJson("/api/v1/organizations/{$organization->public_id}/ivrs", [
            'service_number_id' => $service->public_id,
            'name' => 'Main menu',
            'options' => [
                ['digit' => '1', 'label' => 'Hours', 'destination_type' => 'directive', 'sort_order' => 0],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['options.0.directive_text']);
    }
}
