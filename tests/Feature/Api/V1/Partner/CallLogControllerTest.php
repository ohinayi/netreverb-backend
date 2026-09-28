<?php

namespace Tests\Feature\Api\V1\Partner;

use App\Models\CallLog;
use App\Models\Organization;
use App\Models\OrganizationApiKey;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CallLogControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_valid_key_lists_only_its_own_organizations_call_logs(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        CallLog::factory()->for($orgA)->create();
        CallLog::factory()->for($orgB)->create();
        $keyA = OrganizationApiKey::generate($orgA->id, 'Test key A');

        $response = $this->getJson('/api/v1/partner/call-logs', [
            'Authorization' => 'Bearer '.$keyA['plaintext'],
        ]);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertCount(1, $ids);
        $this->assertSame($orgA->callLogs()->value('public_id'), $ids->first());
    }

    public function test_a_key_cannot_show_another_organizations_call_log(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $callLogB = CallLog::factory()->for($orgB)->create();
        $keyA = OrganizationApiKey::generate($orgA->id, 'Test key A');

        $this->getJson("/api/v1/partner/call-logs/{$callLogB->public_id}", [
            'Authorization' => 'Bearer '.$keyA['plaintext'],
        ])->assertNotFound();
    }

    public function test_a_key_can_show_its_own_organizations_call_log(): void
    {
        $organization = Organization::factory()->create();
        $callLog = CallLog::factory()->for($organization)->create();
        $key = OrganizationApiKey::generate($organization->id, 'Test key');

        $response = $this->getJson("/api/v1/partner/call-logs/{$callLog->public_id}", [
            'Authorization' => 'Bearer '.$key['plaintext'],
        ]);

        $response->assertOk()->assertJsonPath('data.id', $callLog->public_id);
    }

    public function test_missing_key_is_rejected(): void
    {
        $this->getJson('/api/v1/partner/call-logs')->assertUnauthorized();
    }

    public function test_malformed_key_is_rejected(): void
    {
        $this->getJson('/api/v1/partner/call-logs', ['Authorization' => 'Bearer not-a-real-key'])
            ->assertUnauthorized();
    }

    public function test_revoked_key_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $generated = OrganizationApiKey::generate($organization->id, 'Revoked key');
        $generated['model']->forceFill(['revoked_at' => now()])->save();

        $this->getJson('/api/v1/partner/call-logs', ['Authorization' => 'Bearer '.$generated['plaintext']])
            ->assertUnauthorized();
    }

    public function test_expired_key_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $generated = OrganizationApiKey::generate($organization->id, 'Expired key');
        $generated['model']->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->getJson('/api/v1/partner/call-logs', ['Authorization' => 'Bearer '.$generated['plaintext']])
            ->assertUnauthorized();
    }

    public function test_exceeding_the_rate_limit_returns_429(): void
    {
        $organization = Organization::factory()->create();
        $key = OrganizationApiKey::generate($organization->id, 'Rate limit test');
        $headers = ['Authorization' => 'Bearer '.$key['plaintext']];

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/partner/call-logs', $headers)->assertOk();
        }

        $this->getJson('/api/v1/partner/call-logs', $headers)->assertStatus(429);
    }
}
