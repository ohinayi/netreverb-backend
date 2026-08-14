<?php

namespace App\Services\SystemMonitoring;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads the current host's own CPU/memory/disk usage straight from /proc
 * and PHP's disk_*_space(). Single-VPS only for now - see the roadmap for
 * multi-VPS support - so this deliberately avoids reaching for a full
 * metrics stack (Prometheus/Grafana) before there's more than one host or
 * any real usage history to justify it.
 */
class SystemResourceSnapshot
{
    /** @return array<string, mixed> */
    public function capture(): array
    {
        return [
            'hostname' => gethostname() ?: 'unknown',
            'captured_at' => now()->toIso8601String(),
            'cpu' => $this->cpu(),
            'memory' => $this->memory(),
            'disk' => $this->disk(),
            'recording_storage' => $this->recordingStorage(),
        ];
    }

    /** @return array<string, mixed> */
    private function cpu(): array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : null;
        $cores = $this->cpuCoreCount();

        return [
            'load_1m' => $load[0] ?? null,
            'load_5m' => $load[1] ?? null,
            'load_15m' => $load[2] ?? null,
            'cores' => $cores,
            'percent' => $load !== false && $load !== null && $cores > 0
                ? round(min(100, ($load[0] / $cores) * 100), 1)
                : null,
        ];
    }

    private function cpuCoreCount(): int
    {
        if (is_readable('/proc/cpuinfo')) {
            $contents = (string) file_get_contents('/proc/cpuinfo');
            $count = preg_match_all('/^processor\s*:/m', $contents);
            if ($count > 0) {
                return $count;
            }
        }

        return 1;
    }

    /** @return array<string, mixed> */
    private function memory(): array
    {
        if (! is_readable('/proc/meminfo')) {
            return ['total_bytes' => null, 'used_bytes' => null, 'percent' => null];
        }

        $lines = file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $values = [];

        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB$/', $line, $matches) === 1) {
                $values[$matches[1]] = (int) $matches[2] * 1024;
            }
        }

        $total = $values['MemTotal'] ?? null;
        $available = $values['MemAvailable'] ?? null;
        $used = $total !== null && $available !== null ? $total - $available : null;

        return [
            'total_bytes' => $total,
            'used_bytes' => $used,
            'percent' => $total ? round((($used ?? 0) / $total) * 100, 1) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function disk(): array
    {
        $path = base_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        $used = $total !== false && $free !== false ? $total - $free : null;

        return [
            'total_bytes' => $total !== false ? $total : null,
            'used_bytes' => $used,
            'percent' => $total ? round((($used ?? 0) / $total) * 100, 1) : null,
        ];
    }

    /**
     * Only the local disks - conference recordings live in Supabase's S3
     * bucket now (see the storage audit), and summing a remote bucket's
     * size on every dashboard load isn't worth the network round trip.
     *
     * @return array<string, mixed>
     */
    private function recordingStorage(): array
    {
        return [
            'call_recordings_bytes' => $this->localDiskUsage('freeswitch_call_recordings'),
            'conference_recordings_bytes' => $this->localDiskUsage('freeswitch_recordings'),
        ];
    }

    private function localDiskUsage(string $diskName): ?int
    {
        $config = config("filesystems.disks.{$diskName}");

        if (! is_array($config) || ($config['driver'] ?? null) !== 'local') {
            return null;
        }

        $disk = Storage::disk($diskName);

        try {
            $total = 0;
            foreach ($disk->allFiles() as $file) {
                $total += $disk->size($file);
            }

            return $total;
        } catch (Throwable) {
            return null;
        }
    }
}
