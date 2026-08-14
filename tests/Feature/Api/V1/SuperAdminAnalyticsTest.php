<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CallRecordingStatus;
use App\Models\CallLog;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\SipProvisioningState;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminAnalyticsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_super_admin_receives_operational_health_without_credentials(): void
    {
        config()->set('payments.enabled', false);
        config()->set('payments.paystack.secret_key', null);
        config()->set('payments.flutterwave.secret_key', null);
        config()->set('outbound.sending_enabled', false);
        $admin = User::factory()->create(['is_super_admin' => true]);
        $organization = Organization::factory()->create();
        $extension = Extension::factory()->for($organization)->create();
        $callLog = CallLog::factory()->for($organization)->create([
            'caller_extension_id' => $extension->id,
            'recording_status' => CallRecordingStatus::Failed,
        ]);
        SipProvisioningState::query()->create([
            'extension_id' => $extension->id,
            'status' => 'failed',
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => fake()->uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Test failure',
            'failed_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/super-admin/analytics')
            ->assertOk()
            ->assertJsonPath('data.health.queue.failed_jobs', 1)
            ->assertJsonPath('data.health.provisioning.failed', 1)
            ->assertJsonPath('data.health.recordings.failed', 1)
            ->assertJsonPath('data.health.outbound_messaging.enabled', false)
            ->assertJsonPath('data.health.payments.enabled', false)
            ->assertJsonMissingPath('data.health.payments.gateways.paystack.secret_key')
            ->assertJsonStructure([
                'data' => [
                    'system' => ['hostname', 'captured_at', 'cpu', 'memory', 'disk', 'recording_storage'],
                ],
            ]);
    }

    public function test_regular_user_cannot_view_platform_health(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/super-admin/analytics')->assertForbidden();
    }
}
