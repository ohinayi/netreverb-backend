<?php

namespace Tests\Feature\Console;

use App\Services\CallRecordings\CallRecordingVpsSynchronizer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncCallRecordingsFromVpsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_dispatches_a_recording_sync_with_defaults(): void
    {
        config()->set('telephony.call_recordings.sync.password', null);

        $this->app->instance(
            CallRecordingVpsSynchronizer::class,
            tap(Mockery::mock(CallRecordingVpsSynchronizer::class), function ($mock): void {
                $mock->shouldReceive('sync')
                    ->once()
                    ->withArgs(function (
                        string $host,
                        string $user,
                        string $remoteBasePath,
                        ?string $remoteRelativePath,
                        ?string $password,
                        bool $dryRun,
                        $output,
                    ): bool {
                        return $host === 'sip.classyra.com.ng'
                            && $user === 'deploy'
                            && $remoteBasePath === '/usr/local/freeswitch/var/lib/freeswitch/recordings/calls'
                            && $remoteRelativePath === null
                            && $password === null
                            && $dryRun === false;
                    })
                    ->andReturn([
                        'dry_run' => false,
                        'remote_host' => 'sip.classyra.com.ng',
                        'remote_path' => '/usr/local/freeswitch/var/lib/freeswitch/recordings/calls/',
                        'local_path' => storage_path('app/public/recordings/calls/'),
                    ]);
            }),
        );

        $this->artisan('recordings:sync-from-vps')
            ->expectsOutputToContain('Recording sync completed')
            ->assertExitCode(0);
    }

    public function test_it_passes_relative_path_and_dry_run_to_the_sync_service(): void
    {
        config()->set('telephony.call_recordings.sync.password', null);

        $this->app->instance(
            CallRecordingVpsSynchronizer::class,
            tap(Mockery::mock(CallRecordingVpsSynchronizer::class), function ($mock): void {
                $mock->shouldReceive('sync')
                    ->once()
                    ->withArgs(function (
                        string $host,
                        string $user,
                        string $remoteBasePath,
                        ?string $remoteRelativePath,
                        ?string $password,
                        bool $dryRun,
                        $output,
                    ): bool {
                        return $remoteRelativePath === '2026/06/30'
                            && $password === null
                            && $dryRun === true;
                    })
                    ->andReturn([
                        'dry_run' => true,
                        'remote_host' => 'sip.classyra.com.ng',
                        'remote_path' => '/usr/local/freeswitch/var/lib/freeswitch/recordings/calls/2026/06/30/',
                        'local_path' => storage_path('app/public/recordings/calls/2026/06/30/'),
                    ]);
            }),
        );

        $this->artisan('recordings:sync-from-vps 2026/06/30 --dry-run')
            ->expectsOutputToContain('Dry-run sync completed')
            ->assertExitCode(0);
    }
}
