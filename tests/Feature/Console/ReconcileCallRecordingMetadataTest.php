<?php

namespace Tests\Feature\Console;

use App\Enums\CallRecordingStatus;
use App\Models\CallLog;
use App\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReconcileCallRecordingMetadataTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_orphans_only_call_recordings_with_missing_local_files(): void
    {
        Storage::fake('freeswitch_call_recordings');

        $organization = Organization::factory()->create();

        $missingRecording = CallLog::factory()->for($organization)->create([
            'recording_id' => (string) Str::ulid(),
            'recording_uuid' => 'missing-call-uuid',
            'recording_file_path' => '2026/06/30/missing.wav',
            'recording_file_name' => 'missing.wav',
            'recording_status' => CallRecordingStatus::Completed,
        ]);

        $existingRecording = CallLog::factory()->for($organization)->create([
            'recording_id' => (string) Str::ulid(),
            'recording_uuid' => 'existing-call-uuid',
            'recording_file_path' => '2026/06/30/existing.wav',
            'recording_file_name' => 'existing.wav',
            'recording_status' => CallRecordingStatus::Completed,
        ]);

        Storage::disk('freeswitch_call_recordings')->put($existingRecording->recording_file_path, 'fake audio');

        $this->artisan('recordings:reconcile-call-metadata')
            ->expectsOutputToContain('Reconciled 1 stale call recording metadata entry')
            ->assertExitCode(0);

        $missingRecording->refresh();
        $existingRecording->refresh();

        $this->assertSame(CallRecordingStatus::Orphaned, $missingRecording->recording_status);
        $this->assertNull($missingRecording->recording_file_path);
        $this->assertNull($missingRecording->recording_url);

        $this->assertSame(CallRecordingStatus::Completed, $existingRecording->recording_status);
        $this->assertSame('2026/06/30/existing.wav', $existingRecording->recording_file_path);
    }
}
