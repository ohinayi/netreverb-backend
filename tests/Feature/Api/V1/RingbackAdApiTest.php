<?php

namespace Tests\Feature\Api\V1;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\RingbackAd;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RingbackAdApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_submit_an_ad_and_it_starts_pending(): void
    {
        Storage::fake('public');
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        Sanctum::actingAs($admin);

        $this->post("/api/v1/organizations/{$organization->public_id}/ringback-ads", [
            'title' => 'Our summer promo',
            'audio' => UploadedFile::fake()->create('ad.wav', 500, 'audio/wav'),
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.enabled', true);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/ringback-ads")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_member_cannot_submit_an_ad(): void
    {
        Storage::fake('public');
        $organization = Organization::factory()->create();
        $member = User::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($member)->create();
        Sanctum::actingAs($member);

        $this->post("/api/v1/organizations/{$organization->public_id}/ringback-ads", [
            'title' => 'Sneaky ad',
            'audio' => UploadedFile::fake()->create('ad.wav', 500, 'audio/wav'),
        ])->assertForbidden();
    }

    public function test_super_admin_can_upload_an_ad_that_is_auto_approved(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

        $this->post('/api/v1/super-admin/ringback-ads', [
            'title' => 'Platform ad',
            'audio' => UploadedFile::fake()->create('ad.wav', 500, 'audio/wav'),
        ])->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.organization_id', null);
    }

    public function test_super_admin_can_approve_a_pending_submission_and_a_regular_user_cannot(): void
    {
        $organization = Organization::factory()->create();
        $ad = RingbackAd::query()->create([
            'organization_id' => $organization->id, 'title' => 'Pending ad',
            'audio_path' => 'ringback-ads/x.wav', 'status' => 'pending', 'enabled' => true,
        ]);

        Sanctum::actingAs(User::factory()->create());
        $this->patchJson("/api/v1/super-admin/ringback-ads/{$ad->public_id}", ['status' => 'approved'])
            ->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));
        $this->patchJson("/api/v1/super-admin/ringback-ads/{$ad->public_id}", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_super_admin_can_delete_an_ad_and_its_file(): void
    {
        Storage::fake('public');
        $path = Storage::disk('public')->putFile('ringback-ads', UploadedFile::fake()->create('ad.wav', 100));
        $ad = RingbackAd::query()->create([
            'title' => 'Old ad', 'audio_path' => $path, 'status' => 'approved', 'enabled' => true,
        ]);
        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

        $this->deleteJson("/api/v1/super-admin/ringback-ads/{$ad->public_id}")->assertNoContent();

        Storage::disk('public')->assertMissing($path);
        $this->assertModelMissing($ad);
    }

    public function test_super_admin_can_toggle_ad_exemption_but_not_for_a_personal_workspace(): void
    {
        $organization = Organization::factory()->create();
        $personal = Organization::factory()->create(['settings' => ['kind' => 'individual']]);
        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

        $this->patchJson("/api/v1/super-admin/organizations/{$organization->public_id}/ad-exemption", [
            'ad_exempt' => true,
        ])->assertOk()->assertJsonPath('data.ad_exempt', true);

        $this->patchJson("/api/v1/super-admin/organizations/{$personal->public_id}/ad-exemption", [
            'ad_exempt' => true,
        ])->assertStatus(422);
    }

    public function test_effective_ringback_audio_reflects_exemption_status(): void
    {
        Storage::fake('public');
        $organization = Organization::factory()->create(['settings' => [
            'ringback_audio' => ['audio_path' => 'ringback-audio/own/hold.wav'],
        ]]);
        $admin = User::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/ringback-audio/effective")
            ->assertOk()
            ->assertJsonPath('data.url', null);

        $organization->update(['ad_exempt' => true]);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/ringback-audio/effective")
            ->assertOk()
            ->assertJsonPath('data.url', '/storage/ringback-audio/own/hold.wav');
    }

    public function test_org_admin_can_request_exemption_once_and_super_admin_can_approve_it(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/organizations/{$organization->public_id}/ad-exemption-request")
            ->assertOk()
            ->assertJsonPath('data.ad_exemption_status', 'pending');

        // A second request while one is already pending is rejected.
        $this->postJson("/api/v1/organizations/{$organization->public_id}/ad-exemption-request")
            ->assertStatus(422);

        Sanctum::actingAs(User::factory()->create());
        $this->patchJson("/api/v1/super-admin/organizations/{$organization->public_id}/ad-exemption-request", [
            'approve' => true,
        ])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));
        $this->patchJson("/api/v1/super-admin/organizations/{$organization->public_id}/ad-exemption-request", [
            'approve' => true,
        ])->assertOk()
            ->assertJsonPath('data.ad_exempt', true)
            ->assertJsonPath('data.ad_exemption_status', 'approved');
    }

    public function test_denying_a_request_leaves_ad_exempt_false_and_allows_a_new_request(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/organizations/{$organization->public_id}/ad-exemption-request")->assertOk();

        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));
        $this->patchJson("/api/v1/super-admin/organizations/{$organization->public_id}/ad-exemption-request", [
            'approve' => false,
        ])->assertOk()
            ->assertJsonPath('data.ad_exempt', false)
            ->assertJsonPath('data.ad_exemption_status', 'rejected');

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/organizations/{$organization->public_id}/ad-exemption-request")
            ->assertOk()
            ->assertJsonPath('data.ad_exemption_status', 'pending');
    }

    public function test_call_ringback_audio_resolves_by_the_callee_number_not_the_caller_org(): void
    {
        $exemptOrg = Organization::factory()->create(['ad_exempt' => true, 'settings' => [
            'ringback_audio' => ['audio_path' => 'ringback-audio/callee/hold.wav'],
        ]]);
        $callee = \App\Models\DialableNumber::factory()->create([
            'organization_id' => $exemptOrg->id, 'number' => '900123',
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/call-ringback-audio?number=900123')
            ->assertOk()
            ->assertJsonPath('data.url', '/storage/ringback-audio/callee/hold.wav');

        $this->getJson('/api/v1/call-ringback-audio?number=000000')
            ->assertOk()
            ->assertJsonPath('data.url', null);
    }
}
