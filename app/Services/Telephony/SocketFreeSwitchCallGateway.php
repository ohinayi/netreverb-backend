<?php

namespace App\Services\Telephony;

use App\Contracts\Telephony\FreeSwitchCallGateway;
use App\Data\CallRecordingProfile;
use App\Exceptions\FreeSwitchTransferException;
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

    public function transfer(
        string $callUuid,
        string $destination,
        string $callerNumber,
        int $ringTimeoutSeconds = 20,
    ): void
    {
        // A blind uuid_transfer immediately removes the current bridge. Create
        // a consultation leg first and replace the bridge only after the target
        // answers. We intentionally do not SIP-hold the caller here: a hold
        // re-INVITE can leave a browser in recvonly mode after uuid_bridge.
        // While the target rings, the existing call remains fully intact.
        $ringTimeoutSeconds = min(60, max(10, $ringTimeoutSeconds));
        $displayCallerNumber = preg_replace('/\D+/', '', $callerNumber) ?: 'Transfer';
        $consultationUuid = null;

        try {
            $originateCommand = sprintf(
                // The consultation leg is deliberately audio-only. Without an
                // absolute codec list, FreeSWITCH may offer its video codecs to
                // a WebRTC browser even though the original call is audio.
                'originate {originate_timeout=%d,ignore_early_media=true,absolute_codec_string=OPUS,origination_caller_id_name=Transfer,origination_caller_id_number=%s}sofia/external/%s@%s:%d &park()',
                $ringTimeoutSeconds,
                $displayCallerNumber,
                $destination,
                config('telephony.sip_server'),
                config('telephony.sip_port'),
            );
            $originateResponse = $this->client->api($originateCommand, $ringTimeoutSeconds + 10);
            $consultationUuid = $this->uuidFromOriginateResponse($originateResponse);
            if ($consultationUuid === null) {
                throw new FreeSwitchTransferException('The destination is unavailable or did not answer in time.');
            }

            // Replaces the current bridge only once the consultation target has
            // answered. If originate failed above, this command is never sent.
            $bridgeCommand = sprintf('uuid_bridge %s %s', $callUuid, $consultationUuid);
            $this->assertSuccessfulResponse($bridgeCommand, $this->client->api($bridgeCommand));
        } catch (\Throwable $exception) {
            if ($consultationUuid !== null) {
                try {
                    $this->client->api(sprintf('uuid_kill %s', $consultationUuid));
                } catch (\Throwable) {
                    // The consultation leg may already have terminated.
                }
            }
            if ($exception instanceof FreeSwitchTransferException) {
                throw $exception;
            }

            throw new FreeSwitchTransferException(
                'The destination could not be reached. The original call has been restored.',
                previous: $exception,
            );
        }
    }

    private function uuidFromOriginateResponse(string $response): ?string
    {
        if (! str_contains($response, '+OK')) {
            return null;
        }

        return preg_match(
            '/\b[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\b/i',
            $response,
            $matches,
        ) === 1 ? strtolower($matches[0]) : null;
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
