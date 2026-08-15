<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiAssistant;
use App\Models\AiAssistantSession;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiAssistantSessionsApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actingAsMember(Organization $organization): void
    {
        $user = User::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($user)->create();
        Sanctum::actingAs($user);
    }

    public function test_it_lists_sessions_across_every_assistant_in_the_organization(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsMember($organization);
        $orders = AiAssistant::query()->create(['organization_id' => $organization->id, 'name' => 'Orders', 'enabled' => true]);
        $support = AiAssistant::query()->create(['organization_id' => $organization->id, 'name' => 'Support', 'enabled' => true]);
        AiAssistantSession::query()->create(['organization_id' => $organization->id, 'ai_assistant_id' => $orders->id, 'status' => 'completed']);
        AiAssistantSession::query()->create(['organization_id' => $organization->id, 'ai_assistant_id' => $support->id, 'status' => 'in_progress']);

        $response = $this->getJson("/api/v1/organizations/{$organization->public_id}/ai-assistant-sessions")->assertOk();

        $names = collect($response->json('data'))->pluck('assistant_name')->sort()->values();
        $this->assertSame(['Orders', 'Support'], $names->all());
    }

    public function test_it_filters_by_assistant_and_status(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsMember($organization);
        $orders = AiAssistant::query()->create(['organization_id' => $organization->id, 'name' => 'Orders', 'enabled' => true]);
        $support = AiAssistant::query()->create(['organization_id' => $organization->id, 'name' => 'Support', 'enabled' => true]);
        AiAssistantSession::query()->create(['organization_id' => $organization->id, 'ai_assistant_id' => $orders->id, 'status' => 'completed']);
        AiAssistantSession::query()->create(['organization_id' => $organization->id, 'ai_assistant_id' => $orders->id, 'status' => 'in_progress']);
        AiAssistantSession::query()->create(['organization_id' => $organization->id, 'ai_assistant_id' => $support->id, 'status' => 'completed']);

        $byAssistant = $this->getJson("/api/v1/organizations/{$organization->public_id}/ai-assistant-sessions?assistant_id={$orders->public_id}")->assertOk();
        $this->assertCount(2, $byAssistant->json('data'));

        $byStatus = $this->getJson("/api/v1/organizations/{$organization->public_id}/ai-assistant-sessions?status=completed")->assertOk();
        $this->assertCount(2, $byStatus->json('data'));
    }

    public function test_a_session_from_another_organization_is_never_returned(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $this->actingAsMember($organization);
        $assistant = AiAssistant::query()->create(['organization_id' => $other->id, 'name' => 'Other Org Assistant', 'enabled' => true]);
        AiAssistantSession::query()->create(['organization_id' => $other->id, 'ai_assistant_id' => $assistant->id, 'status' => 'completed']);

        $response = $this->getJson("/api/v1/organizations/{$organization->public_id}/ai-assistant-sessions")->assertOk();

        $this->assertCount(0, $response->json('data'));
    }
}
