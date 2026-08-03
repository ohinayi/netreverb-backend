<?php

namespace Tests\Feature\Console;

use App\Enums\CallRecordingStatus;
use App\Jobs\CleanupCallRecordings;
use App\Models\CallLog;
use App\Models\Organization;
use App\Services\CallRecordings\CallRecordingManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CleanupCallRecordingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_cleanup_job_removes_old_and_orphaned_call_recordings(): void
    {
        Storage::fake('freeswitch_call_recordings');

        $organization = Organization::factory()->create();

        $oldCompleted = CallLog::factory()->for($organization)->create([
            'recording_id' => (string) Str::ulid(),
            'recording_uuid' => 'old-call-uuid',
            'recording_file_path' => '2026/05/01/old-completed.wav',
            'recording_file_name' => 'old-completed.wav',
            'recording_status' => CallRecordingStatus::Completed,
            'recording_started_at' => now()->subDays(31),
            'recording_ended_at' => now()->subDays(31),
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ]);

        $missingRecording = CallLog::factory()->for($organization)->create([
            'recording_id' => (string) Str::ulid(),
            'recording_uuid' => 'missing-call-uuid',
            'recording_file_path' => '2026/05/01/missing-orphan.wav',
            'recording_file_name' => 'missing-orphan.wav',
            'recording_status' => CallRecordingStatus::Recording,
            'recording_started_at' => now()->subDays(31),
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ]);

        Storage::disk('freeswitch_call_recordings')->put($oldCompleted->recording_file_path, 'fake audio');

        $this->app->make(CleanupCallRecordings::class)
            ->handle($this->app->make(CallRecordingManager::class));

        Storage::disk('freeswitch_call_recordings')->assertMissing($oldCompleted->recording_file_path);

        $oldCompleted->refresh();
        $missingRecording->refresh();

        $this->assertSame(CallRecordingStatus::Orphaned, $oldCompleted->recording_status);
        $this->assertSame(CallRecordingStatus::Orphaned, $missingRecording->recording_status);
        $this->assertNull($oldCompleted->recording_file_path);
        $this->assertNull($missingRecording->recording_file_path);
    }

    public function test_cleanup_honors_each_organization_recording_retention_policy(): void
    {
        Storage::fake('freeswitch_call_recordings');

        $organization = Organization::factory()->create([
            'settings' => [
                'operational_policy' => ['recording_retention_days' => 90],
            ],
        ]);
        $recording = CallLog::factory()->for($organization)->create([
            'recording_id' => (string) Str::ulid(),
            'recording_uuid' => 'retained-call-uuid',
            'recording_file_path' => '2026/05/01/retained.wav',
            'recording_file_name' => 'retained.wav',
            'recording_status' => CallRecordingStatus::Completed,
            'recording_started_at' => now()->subDays(45),
            'recording_ended_at' => now()->subDays(45),
            'created_at' => now()->subDays(45),
            'updated_at' => now()->subDays(45),
        ]);
        Storage::disk('freeswitch_call_recordings')->put($recording->recording_file_path, 'fake audio');

        $this->app->make(CleanupCallRecordings::class)
            ->handle($this->app->make(CallRecordingManager::class));

        Storage::disk('freeswitch_call_recordings')->assertExists($recording->recording_file_path);
        $this->assertSame(CallRecordingStatus::Completed, $recording->refresh()->recording_status);
    }
}
