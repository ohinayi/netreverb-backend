<?php

namespace Tests\Feature;

use App\Contracts\Telephony\FreeSwitchCallGateway;
use App\Enums\CallRecordingStatus;
use App\Jobs\SyncCallRecordingFromVps;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class CallRecordingApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_owner_can_start_stop_play_back_and_delete_a_call_recording(): void
    {
        Storage::fake('freeswitch_call_recordings');
        Bus::fake();

        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->owner()->for($organization)->for($owner)->create();
        Sanctum::actingAs($owner);

        $callLog = CallLog::factory()->for($organization)->create([
            'freeswitch_uuid' => 'call-uuid-1234',
            'recording_status' => null,
            'recording_url' => null,
        ]);

        $gateway = Mockery::mock(FreeSwitchCallGateway::class);
        $recordingPath = null;

        $gateway->shouldReceive('startRecording')
            ->once()
            ->withArgs(function (string $callUuid, string $absolutePath) use (&$recordingPath): bool {
                $recordingPath = $absolutePath;

                return $callUuid === 'call-uuid-1234' && str_ends_with($absolutePath, '.wav');
            });

        $gateway->shouldReceive('stopRecording')
            ->once()
            ->withArgs(function (string $callUuid, string $absolutePath) use (&$recordingPath): bool {
                return $callUuid === 'call-uuid-1234'
                    && $absolutePath === $recordingPath;
            });

        $this->app->instance(FreeSwitchCallGateway::class, $gateway);

        $startResponse = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}/recording/start",
            [],
        );

        $startResponse->assertOk()
            ->assertJsonPath('data.recording.status', CallRecordingStatus::Recording->value)
            ->assertJsonPath('data.recording.playback_available', false);

        $callLog->refresh();
        $recordingFilePath = $callLog->recording_file_path;

        Storage::disk('freeswitch_call_recordings')->put($recordingFilePath, 'fake audio');

        $stopResponse = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}/recording/stop",
        );

        $stopResponse->assertOk()
            ->assertJsonPath('data.recording.status', CallRecordingStatus::Completed->value)
            ->assertJsonPath('data.recording.playback_available', true);

        Bus::assertDispatchedAfterResponse(SyncCallRecordingFromVps::class, function (SyncCallRecordingFromVps $job) use ($callLog): bool {
            return $job->callLogId === $callLog->id;
        });

        $playbackResponse = $this->get(
            "/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}/recording",
        );

        $playbackResponse->assertOk();

        $deleteResponse = $this->deleteJson(
            "/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}/recording",
        );

        $deleteResponse->assertOk()
            ->assertJsonPath('message', 'Call recording deleted.');

        Storage::disk('freeswitch_call_recordings')->assertMissing($recordingFilePath);

        $callLog->refresh();
        $this->assertNull($callLog->recording_url);
        $this->assertNull($callLog->recording_file_path);
        $this->assertNull($callLog->recording_uuid);
    }

    public function test_start_recording_requires_a_freeswitch_uuid(): void
    {
        Storage::fake('freeswitch_call_recordings');

        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->owner()->for($organization)->for($owner)->create();
        Sanctum::actingAs($owner);

        $callLog = CallLog::factory()->for($organization)->create([
            'freeswitch_uuid' => null,
        ]);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}/recording/start",
            [],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['recording_uuid']);
    }

    public function test_stop_recording_marks_call_as_completed_even_when_file_is_not_yet_available_locally(): void
    {
        Storage::fake('freeswitch_call_recordings');
        Bus::fake();

        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->owner()->for($organization)->for($owner)->create();
        Sanctum::actingAs($owner);

        $callLog = CallLog::factory()->for($organization)->create([
            'freeswitch_uuid' => 'call-uuid-1234',
            'recording_uuid' => 'call-uuid-1234',
            'recording_file_path' => '2026/06/30/missing.wav',
            'recording_file_name' => 'missing.wav',
            'recording_status' => CallRecordingStatus::Recording,
            'recording_started_at' => now()->subSeconds(15),
        ]);

        $gateway = Mockery::mock(FreeSwitchCallGateway::class);
        $gateway->shouldReceive('stopRecording')
            ->once()
            ->withArgs(function (string $callUuid, string $absolutePath): bool {
                return $callUuid === 'call-uuid-1234'
                    && str_ends_with($absolutePath, '2026/06/30/missing.wav');
            });

        $this->app->instance(FreeSwitchCallGateway::class, $gateway);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}/recording/stop",
        )->assertOk()
            ->assertJsonPath('data.recording.status', CallRecordingStatus::Completed->value)
            ->assertJsonPath('data.recording.playback_available', false);

        Bus::assertDispatchedAfterResponse(SyncCallRecordingFromVps::class, function (SyncCallRecordingFromVps $job) use ($callLog): bool {
            return $job->callLogId === $callLog->id;
        });
    }

    public function test_call_without_recording_returns_null_recording_object(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->owner()->for($organization)->for($owner)->create();
        Sanctum::actingAs($owner);

        $callLog = CallLog::factory()->for($organization)->create([
            'recording_url' => null,
            'recording_file_path' => null,
            'recording_status' => null,
        ]);

        $this->getJson("/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}")
            ->assertOk()
            ->assertJsonPath('data.recording', null);
    }
}
