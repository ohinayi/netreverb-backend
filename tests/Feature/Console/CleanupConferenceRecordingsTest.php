<?php

namespace Tests\Feature\Console;

use App\Enums\ConferenceRecordingStatus;
use App\Jobs\CleanupConferenceRecordings;
use App\Models\ConferenceRecording;
use App\Models\ConferenceRoom;
use App\Models\Organization;
use App\Models\User;
use App\Services\ConferenceRecordings\ConferenceRecordingManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupConferenceRecordingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_cleanup_job_removes_old_and_orphaned_recordings(): void
    {
        Storage::fake('freeswitch_recordings');

        $organization = Organization::factory()->create();
        $host = User::factory()->create();
        $room = ConferenceRoom::factory()->for($organization)->for($host, 'hostUser')->create();

        $oldCompleted = ConferenceRecording::factory()->for($room, 'conferenceRoom')->create([
            'status' => ConferenceRecordingStatus::Completed,
            'file_path' => '2026/05/01/old-completed.wav',
            'file_name' => 'old-completed.wav',
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ]);

        $orphaned = ConferenceRecording::factory()->for($room, 'conferenceRoom')->create([
            'status' => ConferenceRecordingStatus::Recording,
            'file_path' => '2026/05/01/missing-orphan.wav',
            'file_name' => 'missing-orphan.wav',
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ]);

        Storage::disk('freeswitch_recordings')->put($oldCompleted->file_path, 'fake audio');

        $this->app->make(CleanupConferenceRecordings::class)
            ->handle($this->app->make(ConferenceRecordingManager::class));

        Storage::disk('freeswitch_recordings')->assertMissing($oldCompleted->file_path);
        $this->assertSoftDeleted($oldCompleted);
        $this->assertSoftDeleted($orphaned);
    }
}
