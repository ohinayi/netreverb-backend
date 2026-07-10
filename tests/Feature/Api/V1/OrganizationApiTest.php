<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CallRecordingAnnouncementTarget;
use App\Enums\MembershipRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_authentication_is_required(): void
    {
        $this->getJson('/api/v1/organizations')->assertUnauthorized();
    }

    public function test_user_can_create_an_organization_and_becomes_its_owner(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/organizations', [
            'name' => 'Classyra Hospital',
            'slug' => 'classyra-hospital',
            'timezone' => 'Africa/Lagos',
            'locale' => 'en',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Classyra Hospital')
            ->assertJsonPath('data.slug', 'classyra-hospital');

        $organization = Organization::query()->sole();
        $membership = OrganizationMembership::query()->sole();

        $this->assertSame($user->id, $membership->user_id);
        $this->assertSame($organization->id, $membership->organization_id);
        $this->assertSame(MembershipRole::Owner, $membership->role);
    }

    public function test_users_cannot_access_another_organization(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->owner()->for($organization)->for($owner)->create();
        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/organizations/{$organization->public_id}")
            ->assertForbidden();
        $this->getJson('/api/v1/organizations')
            ->assertOk()
            ->assertJsonMissing(['id' => $organization->public_id]);
    }

    public function test_admin_can_update_but_member_cannot_update_an_organization(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        OrganizationMembership::factory()->for($organization)->for($member)->create();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/organizations/{$organization->public_id}", [
            'name' => 'Updated Organization',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Organization');

        Sanctum::actingAs($member);
        $this->patchJson("/api/v1/organizations/{$organization->public_id}", [
            'name' => 'Unauthorized Change',
        ])->assertForbidden();
    }

    public function test_admin_can_update_call_recording_announcement_settings(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/organizations/{$organization->public_id}", [
            'settings' => [
                'call_recording_announcement' => [
                    'enabled' => true,
                    'target' => CallRecordingAnnouncementTarget::Caller->value,
                    'audio_path' => '/usr/local/freeswitch/sounds/custom/recording_notice.wav',
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.settings.call_recording_announcement.enabled', true)
            ->assertJsonPath('data.settings.call_recording_announcement.target', CallRecordingAnnouncementTarget::Caller->value)
            ->assertJsonPath('data.settings.call_recording_announcement.audio_path', '/usr/local/freeswitch/sounds/custom/recording_notice.wav');

        $organization->refresh();

        $this->assertSame([
            'call_recording_announcement' => [
                'enabled' => true,
                'target' => CallRecordingAnnouncementTarget::Caller->value,
                'audio_path' => '/usr/local/freeswitch/sounds/custom/recording_notice.wav',
            ],
        ], $organization->settings);
    }
}
