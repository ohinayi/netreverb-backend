<?php

namespace Tests\Feature\Console;

use App\Notifications\TelephonyInfrastructureAlert;
use App\Services\SystemMonitoring\TelephonyInfrastructureHealth;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class CheckTelephonyInfrastructureHealthTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const CACHE_KEY = 'telephony_infrastructure_health_was_healthy';

    protected function setUp(): void
    {
        parent::setUp();
        config(['superadmin.email' => 'admin@example.com']);
        Cache::forget(self::CACHE_KEY);
    }

    private function fakeHealth(bool $healthy): void
    {
        $this->app->instance(TelephonyInfrastructureHealth::class, Mockery::mock(
            TelephonyInfrastructureHealth::class,
            fn ($mock) => $mock->shouldReceive('check')->andReturn([
                'checked_at' => now()->toIso8601String(),
                'healthy' => $healthy,
                'services' => [
                    'kamailio' => ['unit' => 'kamailio', 'status' => $healthy ? 'active' : 'failed'],
                    'freeswitch' => [
                        'unit' => 'freeswitch',
                        'status' => 'active',
                        'esl' => ['reachable' => true, 'detail' => null],
                    ],
                    'rtpengine' => ['unit' => 'rtpengine', 'status' => 'active'],
                    'coturn' => ['unit' => 'coturn', 'status' => 'active'],
                ],
            ]),
        ));
    }

    public function test_the_first_run_ever_records_state_without_alerting(): void
    {
        Notification::fake();
        $this->fakeHealth(true);

        Artisan::call('telephony:check-infrastructure-health');

        Notification::assertNothingSent();
        $this->assertTrue(Cache::get(self::CACHE_KEY));
    }

    public function test_a_transition_from_healthy_to_unhealthy_sends_one_alert(): void
    {
        Notification::fake();
        Cache::forever(self::CACHE_KEY, true);
        $this->fakeHealth(false);

        Artisan::call('telephony:check-infrastructure-health');

        Notification::assertSentOnDemand(TelephonyInfrastructureAlert::class);
        $this->assertFalse(Cache::get(self::CACHE_KEY));
    }

    public function test_a_transition_from_unhealthy_to_healthy_sends_a_recovery_alert(): void
    {
        Notification::fake();
        Cache::forever(self::CACHE_KEY, false);
        $this->fakeHealth(true);

        Artisan::call('telephony:check-infrastructure-health');

        Notification::assertSentOnDemand(TelephonyInfrastructureAlert::class);
        $this->assertTrue(Cache::get(self::CACHE_KEY));
    }

    public function test_staying_unhealthy_across_runs_does_not_resend_the_alert(): void
    {
        Notification::fake();
        Cache::forever(self::CACHE_KEY, false);
        $this->fakeHealth(false);

        Artisan::call('telephony:check-infrastructure-health');

        Notification::assertNothingSent();
    }
}
