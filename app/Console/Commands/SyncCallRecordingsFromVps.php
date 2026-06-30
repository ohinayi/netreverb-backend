<?php

namespace App\Console\Commands;

use App\Services\CallRecordings\CallRecordingVpsSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('recordings:sync-from-vps
    {path? : Optional remote recordings subpath such as 2026/06/30}
    {--host=sip.classyra.com.ng : Remote VPS hostname}
    {--user=deploy : SSH username for the VPS}
    {--remote-base=/usr/local/freeswitch/var/lib/freeswitch/recordings/calls : Remote call recordings base directory}
    {--dry-run : Preview the sync without copying files}')]
#[Description('Sync FreeSWITCH call recordings from the VPS into local storage')]
class SyncCallRecordingsFromVps extends Command
{
    public function handle(CallRecordingVpsSynchronizer $synchronizer): int
    {
        try {
            $result = $synchronizer->sync(
                host: (string) $this->option('host'),
                user: (string) $this->option('user'),
                remoteBasePath: (string) $this->option('remote-base'),
                remoteRelativePath: $this->argument('path') !== null ? (string) $this->argument('path') : null,
                dryRun: (bool) $this->option('dry-run'),
                output: $this->output,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s completed from %s:%s to %s.',
            $result['dry_run'] ? 'Dry-run sync' : 'Recording sync',
            $result['remote_host'],
            $result['remote_path'],
            $result['local_path'],
        ));

        return self::SUCCESS;
    }
}
