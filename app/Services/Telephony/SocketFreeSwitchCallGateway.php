<?php

namespace App\Services\Telephony;

use App\Contracts\Telephony\FreeSwitchCallGateway;
use App\Exceptions\FreeSwitchRecordingException;

class SocketFreeSwitchCallGateway implements FreeSwitchCallGateway
{
    public function __construct(private readonly FreeSwitchEventSocketClient $client) {}

    public function startRecording(string $callUuid, string $absolutePath): void
    {
        $command = sprintf(
            'uuid_record %s start %s',
            $callUuid,
            $absolutePath,
        );

        $response = $this->client->api($command);

        $this->assertSuccessfulResponse($command, $response);
    }

    public function stopRecording(string $callUuid, string $absolutePath): void
    {
        $command = sprintf(
            'uuid_record %s stop %s',
            $callUuid,
            $absolutePath,
        );

        $response = $this->client->api($command);

        $this->assertSuccessfulResponse($command, $response);
    }

    private function assertSuccessfulResponse(string $command, string $response): void
    {
        if (str_contains($response, '+OK')) {
            return;
        }

        throw FreeSwitchRecordingException::commandFailed($command, $response);
    }
}
