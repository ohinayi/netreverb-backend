<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\SystemMonitoring\TelephonyInfrastructureHealth;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SuperAdminOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_super_admin_sees_telephony_infrastructure_health(): void
    {
        $this->app->instance(TelephonyInfrastructureHealth::class, Mockery::mock(
            TelephonyInfrastructureHealth::class,
            fn ($mock) => $mock->shouldReceive('check')->andReturn([
                'checked_at' => now()->toIso8601String(),
                'healthy' => false,
                'services' => [
                    'kamailio' => ['unit' => 'kamailio', 'status' => 'active'],
                    'freeswitch' => [
                        'unit' => 'freeswitch',
                        'status' => 'active',
                        'esl' => ['reachable' => true, 'detail' => 'UP'],
                    ],
                    'rtpengine' => ['unit' => 'rtpengine', 'status' => 'failed'],
                    'coturn' => ['unit' => 'coturn', 'status' => 'active'],
                ],
            ]),
        ));

        Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

        $this->getJson('/api/v1/super-admin/operations')
            ->assertOk()
            ->assertJsonPath('data.healthy', false)
            ->assertJsonPath('data.services.rtpengine.status', 'failed')
            ->assertJsonPath('data.services.freeswitch.esl.reachable', true);
    }

    public function test_regular_user_cannot_view_operations_health(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/super-admin/operations')->assertForbidden();
    }
}
