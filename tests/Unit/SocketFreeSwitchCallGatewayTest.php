<?php

namespace Tests\Unit;

use App\Data\CallRecordingProfile;
use App\Enums\CallRecordingMediaType;
use App\Enums\CallSessionType;
use App\Exceptions\FreeSwitchRecordingException;
use App\Services\Telephony\FreeSwitchEventSocketClient;
use App\Services\Telephony\SocketFreeSwitchCallGateway;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SocketFreeSwitchCallGatewayTest extends TestCase
{
    public function test_audio_recording_uses_uuid_record_command(): void
    {
        $client = Mockery::mock(FreeSwitchEventSocketClient::class);
        $client->shouldReceive('api')
            ->once()
            ->with('uuid_record call-uuid-1234 start /recordings/test.wav')
            ->andReturn('+OK');

        $gateway = new SocketFreeSwitchCallGateway($client);

        $gateway->startRecording(
            'call-uuid-1234',
            '/recordings/test.wav',
            new CallRecordingProfile(
                sessionType: CallSessionType::Direct,
                mediaType: CallRecordingMediaType::Audio,
                container: 'wav',
            ),
        );
    }

    public function test_video_recording_uses_configured_command_template(): void
    {
        config()->set('telephony.webrtc.recording.direct_video_start_command_template', 'luarun video_start.lua {call_uuid} {absolute_output_path} {media_type} {container}');

        $client = Mockery::mock(FreeSwitchEventSocketClient::class);
        $client->shouldReceive('api')
            ->once()
            ->with('luarun video_start.lua call-uuid-1234 /recordings/test.mp4 video mp4')
            ->andReturn('+OK');

        $gateway = new SocketFreeSwitchCallGateway($client);

        $gateway->startRecording(
            'call-uuid-1234',
            '/recordings/test.mp4',
            new CallRecordingProfile(
                sessionType: CallSessionType::Direct,
                mediaType: CallRecordingMediaType::Video,
                container: 'mp4',
            ),
        );
    }

    public function test_video_recording_requires_command_template_configuration(): void
    {
        config()->set('telephony.webrtc.recording.direct_video_start_command_template', null);

        $gateway = new SocketFreeSwitchCallGateway(Mockery::mock(FreeSwitchEventSocketClient::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Direct video recording start command template is not configured.');

        $gateway->startRecording(
            'call-uuid-1234',
            '/recordings/test.mp4',
            new CallRecordingProfile(
                sessionType: CallSessionType::Direct,
                mediaType: CallRecordingMediaType::Video,
                container: 'mp4',
            ),
        );
    }

    public function test_failed_freeswitch_response_throws_recording_exception(): void
    {
        $client = Mockery::mock(FreeSwitchEventSocketClient::class);
        $client->shouldReceive('api')
            ->once()
            ->with('uuid_record call-uuid-1234 stop /recordings/test.wav')
            ->andReturn('-ERR failed');

        $gateway = new SocketFreeSwitchCallGateway($client);

        $this->expectException(FreeSwitchRecordingException::class);

        $gateway->stopRecording(
            'call-uuid-1234',
            '/recordings/test.wav',
            new CallRecordingProfile(
                sessionType: CallSessionType::Direct,
                mediaType: CallRecordingMediaType::Audio,
                container: 'wav',
            ),
        );
    }
}
