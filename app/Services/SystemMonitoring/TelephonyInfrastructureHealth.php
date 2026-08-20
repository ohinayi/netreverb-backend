<?php

namespace App\Services\SystemMonitoring;

use App\Services\Telephony\FreeSwitchEventSocketClient;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Checks the live status of the telephony services this VPS depends on -
 * Kamailio (SIP proxy), FreeSWITCH (media/IVR), RTPengine (media relay),
 * coturn (STUN/TURN). `systemctl is-active` needs no special privilege to
 * read (only start/stop/restart do), so this works from the same
 * unprivileged user PHP-FPM and the queue worker already run as.
 *
 * Single-VPS only, same reasoning as SystemResourceSnapshot - this reads
 * the local host directly rather than reaching for a metrics stack before
 * there's more than one host to justify it.
 */
class TelephonyInfrastructureHealth
{
    /** @var array<string, string> service key => systemd unit name */
    private const SERVICES = [
        'kamailio' => 'kamailio',
        'freeswitch' => 'freeswitch',
        'rtpengine' => 'rtpengine',
        'coturn' => 'coturn',
    ];

    public function __construct(private readonly FreeSwitchEventSocketClient $eventSocket) {}

    /** @return array<string, mixed> */
    public function check(): array
    {
        $services = [];
        foreach (self::SERVICES as $key => $unit) {
            $services[$key] = [
                'unit' => $unit,
                'status' => $this->systemdStatus($unit),
            ];
        }

        $services['freeswitch']['esl'] = $this->freeSwitchEslStatus();

        return [
            'checked_at' => now()->toIso8601String(),
            'services' => $services,
            'healthy' => collect($services)->every(
                fn (array $service): bool => $service['status'] === 'active'
                    && ($service['esl']['reachable'] ?? true),
            ),
        ];
    }

    private function systemdStatus(string $unit): string
    {
        $process = new Process(['systemctl', 'is-active', $unit]);
        $process->setTimeout(5);

        try {
            $process->run();

            return trim($process->getOutput()) ?: 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /** @return array<string, mixed> */
    private function freeSwitchEslStatus(): array
    {
        try {
            $response = $this->eventSocket->api('status', timeoutSeconds: 5);

            return [
                'reachable' => str_contains($response, 'UP') || $response !== '',
                'detail' => $response !== '' ? $response : null,
            ];
        } catch (Throwable $exception) {
            return [
                'reachable' => false,
                'detail' => $exception->getMessage(),
            ];
        }
    }
}
