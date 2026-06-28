<?php

namespace Tests\Feature\Console;

use App\Contracts\Recordings\CallRecordingStorage;
use App\Contracts\Telephony\FreeSwitchCallGateway;
use App\Enums\CallRecordingStatus;
use App\Enums\CallStatus;
use App\Models\CallLog;
use App\Models\Organization;
use App\Services\CallRecordings\CallRecordingManager;
use App\Services\Telephony\FreeSwitchCallUuidSynchronizer;
use App\Services\Telephony\FreeSwitchEventSocketClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class SyncFreeSwitchCallUuidsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_syncs_the_real_freeswitch_uuid_and_starts_recording(): void
    {
        Storage::fake('freeswitch_call_recordings');

        $organization = Organization::factory()->create();
        $callLog = CallLog::factory()->for($organization)->create([
            'caller_number' => '1001',
            'callee_number' => '101',
            'status' => CallStatus::Ringing,
            'freeswitch_uuid' => null,
            'recording_status' => null,
        ]);

        $client = Mockery::mock(FreeSwitchEventSocketClient::class);
        $client->shouldReceive('api')
            ->once()
            ->with('show channels as json')
            ->andReturn(json_encode([
                'rows' => [
                    [
                        'uuid' => 'fs-uuid-1234',
                        'caller_id_number' => '1001',
                        'destination_number' => 'vb-101',
                    ],
                ],
            ]));

        $gateway = Mockery::mock(FreeSwitchCallGateway::class);
        $gateway->shouldReceive('startRecording')
            ->once()
            ->withArgs(function (string $callUuid, string $absolutePath): bool {
                return $callUuid === 'fs-uuid-1234' && str_ends_with($absolutePath, '.wav');
            });

        $this->app->instance(FreeSwitchEventSocketClient::class, $client);
        $this->app->instance(FreeSwitchCallGateway::class, $gateway);

        $this->artisan('telephony:sync-freeswitch-call-uuids')
            ->assertExitCode(0);

        $callLog->refresh();

        $this->assertSame('fs-uuid-1234', $callLog->freeswitch_uuid);
        $this->assertSame(CallStatus::InProgress, $callLog->status);
        $this->assertSame(CallRecordingStatus::Recording, $callLog->recording_status);
        $this->assertNotNull($callLog->recording_file_path);
    }

    public function test_it_syncs_the_real_freeswitch_uuid_from_event_stream_when_channels_snapshot_is_empty(): void
    {
        Storage::fake('freeswitch_call_recordings');

        $organization = Organization::factory()->create();
        $callLog = CallLog::factory()->for($organization)->create([
            'caller_number' => '1001',
            'callee_number' => '101',
            'status' => CallStatus::Ringing,
            'freeswitch_uuid' => null,
            'recording_status' => null,
        ]);

        $client = Mockery::mock(FreeSwitchEventSocketClient::class);
        $client->shouldReceive('api')
            ->once()
            ->with('show channels as json')
            ->andReturn(json_encode([
                'row_count' => 0,
            ]));

        $client->shouldReceive('api')
            ->once()
            ->with('show calls as json')
            ->andReturn(json_encode([
                'row_count' => 0,
            ]));

        $client->shouldReceive('events')
            ->once()
            ->andReturn([
                [
                    'headers' => [
                        'content-type' => 'text/event-plain',
                    ],
                    'body' => implode("\n", [
                        'Event-Name: CHANNEL_CREATE',
                        'Unique-ID: fs-uuid-5678',
                        'Caller-Caller-ID-Number: 1001',
                        'Caller-Destination-Number: vb-101',
                    ]),
                    'reply_text' => '',
                ],
            ]);

        $gateway = Mockery::mock(FreeSwitchCallGateway::class);
        $gateway->shouldReceive('startRecording')
            ->once()
            ->withArgs(function (string $callUuid, string $absolutePath): bool {
                return $callUuid === 'fs-uuid-5678' && str_ends_with($absolutePath, '.wav');
            });

        $this->app->instance(FreeSwitchEventSocketClient::class, $client);
        $this->app->instance(FreeSwitchCallGateway::class, $gateway);

        $this->artisan('telephony:sync-freeswitch-call-uuids')
            ->assertExitCode(0);

        $callLog->refresh();

        $this->assertSame('fs-uuid-5678', $callLog->freeswitch_uuid);
        $this->assertSame(CallStatus::InProgress, $callLog->status);
        $this->assertSame(CallRecordingStatus::Recording, $callLog->recording_status);
        $this->assertNotNull($callLog->recording_file_path);
    }

    public function test_it_can_sync_from_event_stream_without_a_channel_snapshot(): void
    {
        Storage::fake('freeswitch_call_recordings');

        $organization = Organization::factory()->create();
        $callLog = CallLog::factory()->for($organization)->create([
            'caller_number' => '1001',
            'callee_number' => '101',
            'status' => CallStatus::Ringing,
            'freeswitch_uuid' => null,
            'recording_status' => null,
        ]);

        $client = Mockery::mock(FreeSwitchEventSocketClient::class);
        $client->shouldReceive('events')
            ->once()
            ->with(Mockery::on(function (array $eventNames): bool {
                sort($eventNames);

                return $eventNames === [
                    'CHANNEL_ANSWER',
                    'CHANNEL_CREATE',
                    'CHANNEL_HANGUP_COMPLETE',
                ];
            }), 1)
            ->andReturn([
                [
                    'headers' => [],
                    'body' => implode("\n", [
                        'Event-Name: CHANNEL_CREATE',
                        'Unique-ID: fs-uuid-9012',
                        'Caller-Caller-ID-Number: 1001',
                        'Caller-Destination-Number: vb-101',
                    ]),
                    'reply_text' => '',
                ],
            ]);

        $gateway = Mockery::mock(FreeSwitchCallGateway::class);
        $gateway->shouldReceive('startRecording')
            ->once()
            ->withArgs(function (string $callUuid, string $absolutePath): bool {
                return $callUuid === 'fs-uuid-9012' && str_ends_with($absolutePath, '.wav');
            });

        $synchronizer = new FreeSwitchCallUuidSynchronizer($client, new CallRecordingManager(
            $this->app->make(CallRecordingStorage::class),
            $gateway,
        ));

        $matched = $synchronizer->syncFromEvents(1);

        $callLog->refresh();

        $this->assertSame(1, $matched);
        $this->assertSame('fs-uuid-9012', $callLog->freeswitch_uuid);
        $this->assertSame(CallStatus::InProgress, $callLog->status);
        $this->assertSame(CallRecordingStatus::Recording, $callLog->recording_status);
    }

    public function test_it_syncs_from_plain_event_headers(): void
    {
        Storage::fake('freeswitch_call_recordings');

        $organization = Organization::factory()->create();
        $callLog = CallLog::factory()->for($organization)->create([
            'caller_number' => '1001',
            'callee_number' => '101',
            'status' => CallStatus::Ringing,
            'freeswitch_uuid' => null,
            'recording_status' => null,
        ]);

        $client = Mockery::mock(FreeSwitchEventSocketClient::class);
        $client->shouldReceive('events')
            ->once()
            ->with(Mockery::on(function (array $eventNames): bool {
                sort($eventNames);

                return $eventNames === [
                    'CHANNEL_ANSWER',
                    'CHANNEL_CREATE',
                    'CHANNEL_HANGUP_COMPLETE',
                ];
            }), 1)
            ->andReturn([
                [
                    'headers' => [
                        'event-name' => 'CHANNEL_CREATE',
                        'unique-id' => 'fs-uuid-plain-headers',
                        'caller-caller-id-number' => '1001',
                        'caller-destination-number' => 'vb-101',
                    ],
                    'body' => '',
                    'reply_text' => '',
                ],
            ]);

        $gateway = Mockery::mock(FreeSwitchCallGateway::class);
        $gateway->shouldReceive('startRecording')
            ->once()
            ->withArgs(function (string $callUuid, string $absolutePath): bool {
                return $callUuid === 'fs-uuid-plain-headers' && str_ends_with($absolutePath, '.wav');
            });

        $synchronizer = new FreeSwitchCallUuidSynchronizer($client, new CallRecordingManager(
            $this->app->make(CallRecordingStorage::class),
            $gateway,
        ));

        $matched = $synchronizer->syncFromEvents(1);

        $callLog->refresh();

        $this->assertSame(1, $matched);
        $this->assertSame('fs-uuid-plain-headers', $callLog->freeswitch_uuid);
        $this->assertSame(CallStatus::InProgress, $callLog->status);
        $this->assertSame(CallRecordingStatus::Recording, $callLog->recording_status);
    }
}
