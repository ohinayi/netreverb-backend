<?php

namespace Tests\Feature;

use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Enums\ConferenceRecordingStatus;
use App\Models\ConferenceRecording;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ConferenceRoomRecordingLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_recording_starts_when_room_is_created_and_stops_when_it_ends(): void
    {
        Storage::fake('freeswitch_recordings');

        $admin = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        Sanctum::actingAs($admin);

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $startedConferenceName = null;
        $startedPath = null;

        $gateway->shouldReceive('startRecording')
            ->once()
            ->withArgs(function (string $conferenceName, string $absolutePath) use (&$startedConferenceName, &$startedPath): bool {
                $startedConferenceName = $conferenceName;
                $startedPath = $absolutePath;

                return str_ends_with($absolutePath, '.wav');
            });

        $gateway->shouldReceive('stopRecording')
            ->once()
            ->withArgs(function (string $conferenceName, string $absolutePath) use (&$startedConferenceName, &$startedPath): bool {
                return $conferenceName === $startedConferenceName
                    && $absolutePath === $startedPath;
            });

        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);

        $createResponse = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms",
            [
                'title' => 'Weekly sync',
            ],
        );

        $createResponse->assertCreated();

        $recording = ConferenceRecording::query()->with('conferenceRoom')->sole();
        $this->assertSame(ConferenceRecordingStatus::Recording, $recording->status);

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$createResponse->json('data.public_id')}/end",
        )->assertOk();

        $recording->refresh();
        $recording->load('conferenceRoom');

        $this->assertSame(ConferenceRecordingStatus::Completed, $recording->status);
        $this->assertSame($organization->id, $recording->conferenceRoom->organization_id);
    }

    public function test_recording_stops_when_the_last_participant_leaves(): void
    {
        Storage::fake('freeswitch_recordings');

        $admin = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->admin()->for($organization)->for($admin)->create();
        Sanctum::actingAs($admin);

        $gateway = Mockery::mock(FreeSwitchConferenceGateway::class);
        $startedConferenceName = null;

        $gateway->shouldReceive('startRecording')
            ->once()
            ->andReturnUsing(function (string $conferenceName, string $absolutePath) use (&$startedConferenceName): void {
                $startedConferenceName = $conferenceName;
            });

        $gateway->shouldReceive('stopRecording')
            ->once()
            ->withArgs(function (string $conferenceName, string $absolutePath) use (&$startedConferenceName): bool {
                return $conferenceName === $startedConferenceName
                    && str_ends_with($absolutePath, '.wav');
            });

        $this->app->instance(FreeSwitchConferenceGateway::class, $gateway);

        $createResponse = $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms",
            [
                'title' => 'Solo call',
            ],
        );

        $createResponse->assertCreated();

        $conferenceRoomPublicId = $createResponse->json('data.public_id');

        $this->postJson(
            "/api/v1/organizations/{$organization->public_id}/conference-rooms/{$conferenceRoomPublicId}/leave",
        )->assertOk();

        $recording = ConferenceRecording::query()->sole();
        $this->assertSame(ConferenceRecordingStatus::Completed, $recording->status);
    }
}
