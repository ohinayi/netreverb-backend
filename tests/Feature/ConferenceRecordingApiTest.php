<?php

namespace Tests\Feature;

use App\Enums\ConferenceRecordingStatus;
use App\Enums\ConferenceTranscriptStatus;
use App\Models\ConferenceRecording;
use App\Models\ConferenceRoom;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConferenceRecordingApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_delete_a_conference_recording_from_disk_and_database(): void
    {
        Storage::fake('freeswitch_recordings');

        $admin = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        Sanctum::actingAs($admin);

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($admin, 'hostUser')->create();
        $recording = ConferenceRecording::factory()->for($conferenceRoom, 'conferenceRoom')->create([
            'status' => ConferenceRecordingStatus::Completed,
            'file_path' => '2026/06/26/test-recording.wav',
            'file_name' => 'test-recording.wav',
        ]);

        Storage::disk('freeswitch_recordings')->put($recording->file_path, 'fake audio');

        $this->deleteJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/recordings/{$recording->recording_id}",
        )->assertNoContent();

        Storage::disk('freeswitch_recordings')->assertMissing($recording->file_path);
        $this->assertSoftDeleted($recording);
    }

    public function test_admin_can_download_a_generated_conference_transcript(): void
    {
        Storage::fake('livekit_recordings');

        $admin = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        Sanctum::actingAs($admin);

        $conferenceRoom = ConferenceRoom::factory()->for($organization)->for($admin, 'hostUser')->create();
        $recording = ConferenceRecording::factory()->for($conferenceRoom, 'conferenceRoom')->create([
            'status' => ConferenceRecordingStatus::Completed,
            'storage_key' => 'conferences/'.$conferenceRoom->public_id.'/recording.mp4',
            'transcription_enabled' => true,
            'transcript_status' => ConferenceTranscriptStatus::Ready,
            'transcript_file_path' => 'conferences/'.$conferenceRoom->public_id.'/transcripts/'.$conferenceRoom->public_id.'.docx',
            'transcript_file_name' => 'transcript.docx',
        ]);

        Storage::disk('livekit_recordings')->put($recording->transcript_file_path, 'fake docx');

        $this->getJson("/api/v1/organizations/{$organization->public_id}/conference-recordings")
            ->assertOk()
            ->assertJsonPath('data.0.transcript_status', 'ready')
            ->assertJsonPath('data.0.transcript_download_url', url("/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/recordings/{$recording->recording_id}/transcript"));

        $this->get(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoom->public_id}/recordings/{$recording->recording_id}/transcript",
        )
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
}
