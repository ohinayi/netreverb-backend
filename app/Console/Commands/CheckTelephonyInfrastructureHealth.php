<?php

namespace App\Console\Commands;

use App\Notifications\TelephonyInfrastructureAlert;
use App\Services\SystemMonitoring\TelephonyInfrastructureHealth;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

#[Signature('telephony:check-infrastructure-health')]
class CheckTelephonyInfrastructureHealth extends Command
{
    // Only alert on a state *change*, not every run - otherwise a genuine
    // outage floods the inbox with one email per schedule tick until it's
    // fixed. This cache key remembers whether the last run was healthy.
    private const CACHE_KEY = 'telephony_infrastructure_health_was_healthy';

    public function handle(TelephonyInfrastructureHealth $health): int
    {
        $result = $health->check();
        $wasHealthy = Cache::get(self::CACHE_KEY);
        Cache::forever(self::CACHE_KEY, $result['healthy']);

        // First run ever (no prior state) - just record it, don't alert on
        // whatever the server happens to be doing when this is first deployed.
        if ($wasHealthy === null) {
            return self::SUCCESS;
        }

        if ($wasHealthy === $result['healthy']) {
            return self::SUCCESS;
        }

        $email = (string) config('superadmin.email');
        if ($email === '') {
            $this->warn('No SUPER_ADMIN_EMAIL configured - skipping telephony health alert.');

            return self::SUCCESS;
        }

        $unhealthy = collect([
            ['key' => 'kamailio', 'label' => 'Kamailio (SIP proxy)', 'status' => $result['services']['kamailio']['status']],
            ['key' => 'freeswitch', 'label' => 'FreeSWITCH (media/IVR)', 'status' => $result['services']['freeswitch']['status']],
            ['key' => 'rtpengine', 'label' => 'RTPengine (media relay)', 'status' => $result['services']['rtpengine']['status']],
            ['key' => 'coturn', 'label' => 'coturn (STUN/TURN)', 'status' => $result['services']['coturn']['status']],
        ])->reject(fn (array $service): bool => $service['status'] === 'active')->values()->all();

        if (! $result['services']['freeswitch']['esl']['reachable']) {
            $unhealthy[] = ['key' => 'freeswitch_esl', 'label' => 'FreeSWITCH event socket', 'status' => 'unreachable'];
        }

        Notification::route('mail', $email)->notify(
            new TelephonyInfrastructureAlert($unhealthy, isRecovery: $result['healthy']),
        );

        return self::SUCCESS;
    }
}
