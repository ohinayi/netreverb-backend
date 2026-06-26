<?php

namespace Tests\Feature;

use App\Contracts\Telephony\FreeSwitchCallGateway;
use App\Enums\CallRecordingStatus;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->owner()->for($organization)->for($owner)->create();
        Sanctum::actingAs($owner);

        $callLog = CallLog::factory()->for($organization)->create([
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
            [
                'recording_uuid' => 'call-uuid-1234',
            ],
        );

        $startResponse->assertOk()
            ->assertJsonPath('data.recording.status', CallRecordingStatus::Recording->value);

        $callLog->refresh();
        $recordingFilePath = $callLog->recording_file_path;

        Storage::disk('freeswitch_call_recordings')->put($recordingFilePath, 'fake audio');

        $stopResponse = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/call-logs/{$callLog->public_id}/recording/stop",
        );

        $stopResponse->assertOk()
            ->assertJsonPath('data.recording.status', CallRecordingStatus::Completed->value);

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
}
