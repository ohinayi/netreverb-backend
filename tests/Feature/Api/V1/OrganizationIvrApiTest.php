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

    public function test_an_ivr_can_be_created_without_a_service_number_to_use_only_as_a_nested_submenu(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsAdmin($organization);

        $response = $this->postJson("/api/v1/organizations/{$organization->public_id}/ivrs", [
            'name' => 'Dressing help',
            'welcome_text' => 'Press 1 for shoes.',
            'options' => [
                ['digit' => '1', 'label' => 'Shoes', 'destination_type' => 'directive', 'directive_text' => 'Put your foot in the shoe.', 'sort_order' => 0],
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('organization_ivrs', ['organization_id' => $organization->id, 'name' => 'Dressing help']);
    }

    public function test_admin_can_build_a_nested_submenu_inline_without_creating_a_separate_ivr_first(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsAdmin($organization);

        $response = $this->postJson("/api/v1/organizations/{$organization->public_id}/ivrs", [
            'name' => 'Main menu',
            'welcome_text' => 'Welcome. Press 1 for dressing issues.',
            'options' => [
                [
                    'digit' => '1', 'label' => 'Dressing issues', 'destination_type' => 'submenu', 'sort_order' => 0,
                    'submenu' => [
                        'welcome_text' => 'Press 1 for shoes.',
                        'options' => [
                            ['digit' => '1', 'label' => 'Shoes', 'destination_type' => 'directive', 'directive_text' => 'Put your foot in the shoe and tie the laces.', 'sort_order' => 0],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseCount('organization_ivrs', 2);
        $parentOption = $response->json('data.options.0');
        $this->assertSame('submenu', $parentOption['destination_type']);
        $this->assertNotEmpty($parentOption['destination']);
        $child = OrganizationIvr::query()->where('public_id', $parentOption['destination'])->with('options')->firstOrFail();
        $this->assertSame('Press 1 for shoes.', $child->welcome_text);
        $this->assertSame('directive', $child->options->first()->destination_type);
    }

    public function test_editing_a_parent_ivr_upserts_its_inline_nested_submenu_in_place(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsAdmin($organization);

        $created = $this->postJson("/api/v1/organizations/{$organization->public_id}/ivrs", [
            'name' => 'Main menu',
            'options' => [
                [
                    'digit' => '1', 'label' => 'Dressing issues', 'destination_type' => 'submenu', 'sort_order' => 0,
                    'submenu' => [
                        'welcome_text' => 'Press 1 for shoes.',
                        'options' => [
                            ['digit' => '1', 'label' => 'Shoes', 'destination_type' => 'directive', 'directive_text' => 'Put your foot in the shoe.', 'sort_order' => 0],
                        ],
                    ],
                ],
            ],
        ])->assertCreated();

        $parentPublicId = $created->json('data.public_id');
        $childPublicId = $created->json('data.options.0.destination');

        $this->postJson("/api/v1/organizations/{$organization->public_id}/ivrs/{$parentPublicId}", [
            '_method' => 'PATCH',
            'name' => 'Main menu',
            'options' => [
                [
                    'digit' => '1', 'label' => 'Dressing issues', 'destination_type' => 'submenu', 'sort_order' => 0,
                    'submenu' => [
                        'public_id' => $childPublicId,
                        'welcome_text' => 'Press 1 for shoes, updated.',
                        'options' => [
                            ['digit' => '1', 'label' => 'Shoes', 'destination_type' => 'directive', 'directive_text' => 'Put your foot in the shoe.', 'sort_order' => 0],
                        ],
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseCount('organization_ivrs', 2);
        $this->assertDatabaseHas('organization_ivrs', ['public_id' => $childPublicId, 'welcome_text' => 'Press 1 for shoes, updated.']);
    }

    public function test_a_submenu_cannot_nest_itself(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsAdmin($organization);
        $ivr = OrganizationIvr::create(['organization_id' => $organization->id, 'name' => 'Loop', 'enabled' => true]);

        $this->postJson("/api/v1/organizations/{$organization->public_id}/ivrs/{$ivr->public_id}", [
            '_method' => 'PATCH',
            'name' => 'Loop',
            'options' => [
                [
                    'digit' => '1', 'label' => 'Back to start', 'destination_type' => 'submenu', 'sort_order' => 0,
                    'submenu' => ['public_id' => $ivr->public_id, 'options' => []],
                ],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['options.0.submenu']);
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
