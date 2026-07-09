<?php

namespace Tests\Feature\Console;

use App\Enums\CallStatus;
use App\Models\CallLog;
use App\Models\Organization;
use App\Services\Telephony\FreeSwitchCallUuidSynchronizer;
use App\Services\Telephony\FreeSwitchEventSocketClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncFreeSwitchCallUuidsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_syncs_the_real_freeswitch_uuid(): void
    {
        $organization = Organization::factory()->create();
        $callLog = CallLog::factory()->for($organization)->create([
            'caller_number' => '1001',
            'callee_number' => '101',
            'status' => CallStatus::Ringing,
            'freeswitch_uuid' => null,
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

        $this->app->instance(FreeSwitchEventSocketClient::class, $client);

        $this->artisan('telephony:sync-freeswitch-call-uuids')
            ->assertExitCode(0);

        $callLog->refresh();

        $this->assertSame('fs-uuid-1234', $callLog->freeswitch_uuid);
        $this->assertSame(CallStatus::InProgress, $callLog->status);
        $this->assertNull($callLog->recording_status);
        $this->assertNull($callLog->recording_file_path);
    }

    public function test_it_syncs_the_real_freeswitch_uuid_from_current_snapshot_field_names(): void
    {
        $organization = Organization::factory()->create();
        $callLog = CallLog::factory()->for($organization)->create([
            'caller_number' => '100000',
            'callee_number' => '101',
            'status' => CallStatus::Ringing,
            'freeswitch_uuid' => null,
        ]);

        $client = Mockery::mock(FreeSwitchEventSocketClient::class);
        $client->shouldReceive('api')
            ->once()
            ->with('show channels as json')
            ->andReturn(json_encode([
                'rows' => [
                    [
                        'uuid' => 'fs-uuid-current-snapshot',
                        'cid_num' => '100000',
                        'dest' => '101',
                    ],
                ],
            ]));

        $this->app->instance(FreeSwitchEventSocketClient::class, $client);

        $this->artisan('telephony:sync-freeswitch-call-uuids')
            ->assertExitCode(0);

        $callLog->refresh();

        $this->assertSame('fs-uuid-current-snapshot', $callLog->freeswitch_uuid);
        $this->assertSame(CallStatus::InProgress, $callLog->status);
        $this->assertNull($callLog->recording_status);
        $this->assertNull($callLog->recording_file_path);
    }

    public function test_it_syncs_the_real_freeswitch_uuid_from_event_stream_when_channels_snapshot_is_empty(): void
    {
        $organization = Organization::factory()->create();
        $callLog = CallLog::factory()->for($organization)->create([
            'caller_number' => '1001',
            'callee_number' => '101',
            'status' => CallStatus::Ringing,
            'freeswitch_uuid' => null,
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
            ->with(Mockery::type('array'), 1)
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

        $this->app->instance(FreeSwitchEventSocketClient::class, $client);

        $this->artisan('telephony:sync-freeswitch-call-uuids')
            ->assertExitCode(0);

        $callLog->refresh();

        $this->assertSame('fs-uuid-5678', $callLog->freeswitch_uuid);
        $this->assertSame(CallStatus::InProgress, $callLog->status);
        $this->assertNull($callLog->recording_status);
        $this->assertNull($callLog->recording_file_path);
    }

    public function test_it_can_sync_from_event_stream_without_a_channel_snapshot(): void
    {
        $organization = Organization::factory()->create();
        $callLog = CallLog::factory()->for($organization)->create([
            'caller_number' => '1001',
            'callee_number' => '101',
            'status' => CallStatus::Ringing,
            'freeswitch_uuid' => null,
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

        $synchronizer = new FreeSwitchCallUuidSynchronizer($client);

        $matched = $synchronizer->syncFromEvents(1);

        $callLog->refresh();

        $this->assertSame(1, $matched);
        $this->assertSame('fs-uuid-9012', $callLog->freeswitch_uuid);
        $this->assertSame(CallStatus::InProgress, $callLog->status);
        $this->assertNull($callLog->recording_status);
    }

    public function test_it_syncs_from_plain_event_headers(): void
    {
        $organization = Organization::factory()->create();
        $callLog = CallLog::factory()->for($organization)->create([
            'caller_number' => '1001',
            'callee_number' => '101',
            'status' => CallStatus::Ringing,
            'freeswitch_uuid' => null,
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

        $synchronizer = new FreeSwitchCallUuidSynchronizer($client);

        $matched = $synchronizer->syncFromEvents(1);

        $callLog->refresh();

        $this->assertSame(1, $matched);
        $this->assertSame('fs-uuid-plain-headers', $callLog->freeswitch_uuid);
        $this->assertSame(CallStatus::InProgress, $callLog->status);
        $this->assertNull($callLog->recording_status);
    }

    public function test_it_passes_the_configured_listen_seconds_to_sync_once(): void
    {
        $this->app->instance(
            FreeSwitchCallUuidSynchronizer::class,
            tap(Mockery::mock(FreeSwitchCallUuidSynchronizer::class), function ($mock): void {
                $mock->shouldReceive('syncOnce')
                    ->once()
                    ->with(2)
                    ->andReturn(0);
            }),
        );

        $this->artisan('telephony:sync-freeswitch-call-uuids --listen-seconds=2')
            ->assertExitCode(0);
    }
}
