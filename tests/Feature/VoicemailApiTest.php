<?php

namespace Tests\Feature;

use App\Models\Extension;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\Voicemail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VoicemailApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_owner_can_list_play_mark_listened_and_delete_a_voicemail(): void
    {
        Storage::fake('freeswitch_voicemails');
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->owner()->for($organization)->for($owner)->create();
        Sanctum::actingAs($owner);

        $extension = Extension::factory()->for($organization)->create();
        Storage::disk('freeswitch_voicemails')->put($extension->public_id.'/20260101-000000_2348012345678.wav', 'fake-audio');
        $voicemail = Voicemail::factory()->for($organization)->for($extension)->create([
            'file_path' => $extension->public_id.'/20260101-000000_2348012345678.wav',
            'caller_number' => '2348012345678',
        ]);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/voicemails")
            ->assertOk()
            ->assertJsonPath('data.0.id', $voicemail->public_id)
            ->assertJsonPath('data.0.caller_number', '2348012345678');

        $this->get("/api/v1/organizations/{$organization->public_id}/voicemails/{$voicemail->public_id}/audio")
            ->assertOk();

        $this->postJson("/api/v1/organizations/{$organization->public_id}/voicemails/{$voicemail->public_id}/listened")
            ->assertOk()
            ->assertJsonPath('data.id', $voicemail->public_id);
        $this->assertNotNull($voicemail->fresh()->listened_at);

        $this->deleteJson("/api/v1/organizations/{$organization->public_id}/voicemails/{$voicemail->public_id}")
            ->assertNoContent();
        $this->assertSoftDeleted($voicemail);
        Storage::disk('freeswitch_voicemails')->assertMissing($voicemail->file_path);
    }

    public function test_a_member_cannot_see_another_extensions_voicemail_without_manage_access(): void
    {
        Storage::fake('freeswitch_voicemails');
        $member = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->for($organization)->for($member)->create(['role' => 'agent']);
        Sanctum::actingAs($member);

        $otherExtension = Extension::factory()->for($organization)->create();
        $voicemail = Voicemail::factory()->for($organization)->for($otherExtension)->create();

        $this->getJson("/api/v1/organizations/{$organization->public_id}/voicemails")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->deleteJson("/api/v1/organizations/{$organization->public_id}/voicemails/{$voicemail->public_id}")
            ->assertForbidden();
    }
}
