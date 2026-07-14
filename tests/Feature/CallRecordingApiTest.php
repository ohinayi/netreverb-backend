<?php

namespace Tests\Feature;

use App\Contracts\Telephony\FreeSwitchCallGateway;
use App\Data\CallRecordingProfile;
use App\Enums\CallMediaType;
use App\Enums\CallRecordingAnnouncementTarget;
use App\Enums\CallRecordingMediaType;
use App\Enums\CallRecordingStatus;
use App\Exceptions\FreeSwitchRecordingException;
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
        config()->set('telephony.call_recordings.announcement.enabled', true);
        config()->set('telephony.call_recordings.announcement.default_target', CallRecordingAnnouncementTarget::Both->value);
        config()->set('telephony.call_recordings.announcement.default_audio_path', '/usr/local/freeswitch/sounds/custom/recording_notice.wav');

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

        $gateway->shouldReceive('announceRecordingStart')
            ->once()
            ->with(
                'call-uuid-1234',
                '/usr/local/freeswitch/sounds/custom/recording_notice.wav',
                CallRecordingAnnouncementTarget::Both->value,
            );

        $gateway->shouldReceive('startRecording')
            ->once()
            ->withArgs(function (string $callUuid, string $absolutePath, CallRecordingProfile $profile) use (&$recordingPath): bool {
                $recordingPath = $absolutePath;

                return $callUuid === 'call-uuid-1234'
                    && str_ends_with($absolutePath, '.wav')
                    && $profile->mediaType === CallRecordingMediaType::Audio
                    && $profile->container === 'wav';
            });

        $gateway->shouldReceive('stopRecording')
            ->once()
            ->withArgs(function (string $callUuid, string $absolutePath, CallRecordingProfile $profile) use (&$recordingPath): bool {
                return $callUuid === 'call-uuid-1234'
                    && $absolutePath === $recordingPath
                    && $profile->mediaType === CallRecordingMediaType::Audio
                    && $profile->container === 'wav';
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

    public function test_video_direct_call_uses_video_recording_profile_when_enabled(): void
    {
        Storage::fake('freeswitch_call_recordings');
        config()->set('telephony.webrtc.recording.direct_video_enabled', true);
        config()->set('telephony.webrtc.recording.direct_video_container', 'mp4');
        config()->set('telephony.webrtc.recording.direct_video_start_command_template', 'luarun video_start.lua {call_uuid} {absolute_output_path} {container}');
        config()->set('telephony.webrtc.recording.direct_video_stop_command_template', 'luarun video_stop.lua {call_uuid} {absolute_output_path} {container}');

        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->owner()->for($organization)->for($owner)->create();
        Sanctum::actingAs($owner);

        $callLog = CallLog::factory()->for($organization)->create([
            'freeswitch_uuid' => 'call-uuid-video-1234',
            'media_type' => CallMediaType::Video,
            'recording_status' => null,
            'recording_url' => null,
        ]);

        $gateway = Mockery::mock(FreeSwitchCallGateway::class);

        $gateway->shouldReceive('announceRecordingStart')->once();
        $gateway->shouldReceive('startRecording')
            ->once()
            ->withArgs(function (string $callUuid, string $absolutePath, CallRecordingProfile $profile): bool {
                return $callUuid === 'call-uuid-video-1234'
                    && str_ends_with($absolutePath, '.mp4')
                    && $profile->mediaType === CallRecordingMediaType::Video
                    && $profile->container === 'mp4';
            });

        $this->app->instance(FreeSwitchCallGateway::class, $gateway);

        $response = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}/recording/start",
            [],
        )->assertOk()
            ->assertJsonPath('data.recording.media_type', CallRecordingMediaType::Video->value)
            ->assertJsonPath('data.recording.container', 'mp4');

        $this->assertStringEndsWith('.mp4', $response->json('data.recording.file_name'));
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
            ->withArgs(function (string $callUuid, string $absolutePath, CallRecordingProfile $profile): bool {
                return $callUuid === 'call-uuid-1234'
                    && str_ends_with($absolutePath, '2026/06/30/missing.wav')
                    && $profile->mediaType === CallRecordingMediaType::Audio;
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

    public function test_stop_recording_still_completes_when_the_freeswitch_session_has_already_ended(): void
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
            'recording_file_path' => '2026/07/10/recoverable.wav',
            'recording_file_name' => 'recoverable.wav',
            'recording_status' => CallRecordingStatus::Recording,
            'recording_started_at' => now()->subSeconds(9),
        ]);

        $gateway = Mockery::mock(FreeSwitchCallGateway::class);
        $gateway->shouldReceive('stopRecording')
            ->once()
            ->andThrow(FreeSwitchRecordingException::commandFailed(
                'uuid_record call-uuid-1234 stop /path/to/recoverable.wav',
                '-ERR Cannot locate session!',
            ));

        $this->app->instance(FreeSwitchCallGateway::class, $gateway);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}/recording/stop",
        )->assertOk()
            ->assertJsonPath('data.recording.status', CallRecordingStatus::Completed->value)
            ->assertJsonPath('data.recording.playback_available', false);

        $callLog->refresh();

        $this->assertSame(CallRecordingStatus::Completed, $callLog->recording_status);
        $this->assertNotNull($callLog->recording_ended_at);

        Bus::assertDispatchedAfterResponse(SyncCallRecordingFromVps::class, function (SyncCallRecordingFromVps $job) use ($callLog): bool {
            return $job->callLogId === $callLog->id;
        });
    }

    public function test_show_queues_a_sync_when_a_completed_recording_is_missing_locally(): void
    {
        Storage::fake('freeswitch_call_recordings');
        Bus::fake();

        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->owner()->for($organization)->for($owner)->create();
        Sanctum::actingAs($owner);

        $callLog = CallLog::factory()->for($organization)->create([
            'recording_file_path' => '2026/07/10/missing.wav',
            'recording_file_name' => 'missing.wav',
            'recording_status' => CallRecordingStatus::Completed,
        ]);

        $this->get("/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}/recording")
            ->assertNotFound();

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
