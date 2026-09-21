<?php

namespace App\Services\Telephony;

use App\Contracts\Telephony\FreeSwitchCallGateway;
use App\Data\CallRecordingProfile;
use App\Enums\CallRecordingMediaType;
use App\Exceptions\FreeSwitchAddPartyException;
use App\Exceptions\FreeSwitchRecordingException;
use App\Exceptions\FreeSwitchTransferException;
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
    ): void {
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

    public function addParty(
        string $callUuid,
        string $destination,
        string $callerNumber,
        string $conferenceName,
        int $ringTimeoutSeconds = 20,
    ): string {
        // Same consultation-leg approach as transfer(): ring the new party
        // first, and only touch the existing legs once it actually answers.
        // No SIP-hold, for the same recvonly-after-hold reason documented on
        // transfer() above.
        $ringTimeoutSeconds = min(60, max(10, $ringTimeoutSeconds));
        $displayCallerNumber = preg_replace('/\D+/', '', $callerNumber) ?: 'Add call';
        $consultationUuid = null;

        try {
            // Confirmed live via `uuid_dump` against a real bridged call:
            // this FreeSWITCH build exposes the bridge partner's uuid as
            // `bridge_uuid` (also `signal_bond`) - `other_leg_uuid` isn't a
            // real channel variable here at all, which silently returned
            // empty every time and made every add-party attempt fail with
            // "no other party to merge with."
            $otherLegUuid = trim($this->client->api(sprintf('uuid_getvar %s bridge_uuid', $callUuid)));
            if ($otherLegUuid === '' || str_starts_with($otherLegUuid, '-ERR') || $otherLegUuid === '_undef_') {
                throw new FreeSwitchAddPartyException('This call has no other party to merge with right now.');
            }

            $originateCommand = sprintf(
                'originate {originate_timeout=%d,ignore_early_media=true,absolute_codec_string=OPUS,origination_caller_id_name=%s,origination_caller_id_number=%s}sofia/external/%s@%s:%d &park()',
                $ringTimeoutSeconds,
                $displayCallerNumber,
                $displayCallerNumber,
                $destination,
                config('telephony.sip_server'),
                config('telephony.sip_port'),
            );
            $originateResponse = $this->client->api($originateCommand, $ringTimeoutSeconds + 10);
            $consultationUuid = $this->uuidFromOriginateResponse($originateResponse);
            if ($consultationUuid === null) {
                throw new FreeSwitchAddPartyException('The destination is unavailable or did not answer in time.');
            }

            // Move all three legs into a fresh, ad-hoc conference room named
            // after this call rather than a 2-way uuid_bridge, which only
            // ever supports two legs. 'inline' makes FreeSWITCH execute the
            // conference application directly against each uuid instead of
            // looking it up in dialplan XML.
            foreach ([$callUuid, $otherLegUuid, $consultationUuid] as $legUuid) {
                $transferCommand = sprintf("uuid_transfer %s 'conference:%s@default' inline", $legUuid, $conferenceName);
                $this->assertSuccessfulResponse($transferCommand, $this->client->api($transferCommand));
            }
        } catch (\Throwable $exception) {
            if ($consultationUuid !== null) {
                try {
                    $this->client->api(sprintf('uuid_kill %s', $consultationUuid));
                } catch (\Throwable) {
                    // The consultation leg may already have terminated.
                }
            }
            if ($exception instanceof FreeSwitchAddPartyException) {
                throw $exception;
            }

            throw new FreeSwitchAddPartyException(
                'The destination could not be reached. The original call has been restored.',
                previous: $exception,
            );
        }

        return $consultationUuid;
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
