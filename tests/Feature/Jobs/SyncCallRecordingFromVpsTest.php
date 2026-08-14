<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SyncCallRecordingFromVps;
use App\Models\CallLog;
use App\Models\Organization;
use App\Services\CallRecordings\CallRecordingVpsSynchronizer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class SyncCallRecordingFromVpsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_syncs_the_recording_directory_and_updates_the_recording_size(): void
    {
        Storage::fake('freeswitch_call_recordings');

        config()->set('telephony.call_recordings.sync.host', 'sip.classyra.com.ng');
        config()->set('telephony.call_recordings.sync.user', 'deploy');
        config()->set('telephony.call_recordings.sync.password', 'secret');
        config()->set('telephony.call_recordings.sync.remote_base', '/usr/local/freeswitch/var/lib/freeswitch/recordings/calls');

        $organization = Organization::factory()->create();
        $callLog = CallLog::factory()->for($organization)->create([
            'recording_file_path' => '2026/07/08/test.wav',
            'recording_file_name' => 'test.wav',
            'recording_size' => null,
        ]);

        $this->app->instance(
            CallRecordingVpsSynchronizer::class,
            tap(Mockery::mock(CallRecordingVpsSynchronizer::class), function ($mock) use ($callLog): void {
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
                            && $remoteRelativePath === '2026/07/08'
                            && $password === 'secret'
                            && $dryRun === false
                            && $output instanceof NullOutput;
                    })
                    ->andReturnUsing(function () use ($callLog): array {
                        Storage::disk('freeswitch_call_recordings')->put($callLog->recording_file_path, 'synced audio');

                        return [
                            'dry_run' => false,
                            'remote_host' => 'sip.classyra.com.ng',
                            'remote_path' => '/usr/local/freeswitch/var/lib/freeswitch/recordings/calls/2026/07/08/',
                            'local_path' => storage_path('app/public/recordings/calls/2026/07/08/'),
                        ];
                    });

                $mock->shouldReceive('deleteRemoteFileIfStale')
                    ->once()
                    ->withArgs(function (
                        string $host,
                        string $user,
                        string $remoteFilePath,
                        ?string $password,
                        int $minAgeMinutes,
                        $output,
                    ): bool {
                        return $host === 'sip.classyra.com.ng'
                            && $user === 'deploy'
                            && $remoteFilePath === '/usr/local/freeswitch/var/lib/freeswitch/recordings/calls/2026/07/08/test.wav'
                            && $password === 'secret'
                            && $minAgeMinutes === 10
                            && $output instanceof NullOutput;
                    });
            }),
        );

        (new SyncCallRecordingFromVps($callLog->id))->handle(
            $this->app->make(CallRecordingVpsSynchronizer::class),
        );

        $callLog->refresh();

        $this->assertSame(
            Storage::disk('freeswitch_call_recordings')->size($callLog->recording_file_path),
            $callLog->recording_size,
        );
    }
}
