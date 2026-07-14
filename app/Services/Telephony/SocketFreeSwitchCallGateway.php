<?php

namespace App\Services\Telephony;

use App\Contracts\Telephony\FreeSwitchCallGateway;
use App\Data\CallRecordingProfile;
use App\Enums\CallRecordingMediaType;
use App\Exceptions\FreeSwitchRecordingException;
use RuntimeException;

class SocketFreeSwitchCallGateway implements FreeSwitchCallGateway
{
    public function __construct(private readonly FreeSwitchEventSocketClient $client) {}

    public function announceRecordingStart(string $callUuid, string $audioPath, string $target): void
    {
        $legs = match ($target) {
            'caller' => ['aleg'],
            'callee' => ['bleg'],
            default => ['aleg', 'bleg'],
        };

        foreach ($legs as $leg) {
            $command = sprintf(
                'uuid_broadcast %s %s %s',
                $callUuid,
                $audioPath,
                $leg,
            );

            $response = $this->client->api($command);

            $this->assertSuccessfulResponse($command, $response);
        }
    }

    public function startRecording(string $callUuid, string $absolutePath, CallRecordingProfile $profile): void
    {
        $command = $this->buildRecordingCommand(
            action: 'start',
            callUuid: $callUuid,
            absolutePath: $absolutePath,
            profile: $profile,
        );

        $response = $this->client->api($command);

        $this->assertSuccessfulResponse($command, $response);
    }

    public function stopRecording(string $callUuid, string $absolutePath, CallRecordingProfile $profile): void
    {
        $command = $this->buildRecordingCommand(
            action: 'stop',
            callUuid: $callUuid,
            absolutePath: $absolutePath,
            profile: $profile,
        );

        $response = $this->client->api($command);

        $this->assertSuccessfulResponse($command, $response);
    }

    private function buildRecordingCommand(
        string $action,
        string $callUuid,
        string $absolutePath,
        CallRecordingProfile $profile,
    ): string {
        if ($profile->mediaType === CallRecordingMediaType::Audio) {
            return sprintf(
                'uuid_record %s %s %s',
                $callUuid,
                $action,
                $absolutePath,
            );
        }

        $template = config(sprintf('telephony.webrtc.recording.direct_video_%s_command_template', $action));

        if (! is_string($template) || trim($template) === '') {
            throw new RuntimeException(sprintf(
                'Direct video recording %s command template is not configured.',
                $action,
            ));
        }

        return strtr($template, [
            '{call_uuid}' => $callUuid,
            '{absolute_output_path}' => $absolutePath,
            '{absolute_path}' => $absolutePath,
            '{media_type}' => $profile->mediaType->value,
            '{container}' => $profile->container,
        ]);
    }

    private function assertSuccessfulResponse(string $command, string $response): void
    {
        if (str_contains($response, '+OK')) {
            return;
        }

        throw FreeSwitchRecordingException::commandFailed($command, $response);
    }
}
