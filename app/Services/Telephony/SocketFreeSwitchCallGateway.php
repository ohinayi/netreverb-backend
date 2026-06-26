<?php

namespace App\Services\Telephony;

use App\Contracts\Telephony\FreeSwitchCallGateway;

class SocketFreeSwitchCallGateway implements FreeSwitchCallGateway
{
    public function __construct(private readonly FreeSwitchEventSocketClient $client) {}

    public function startRecording(string $callUuid, string $absolutePath): void
    {
        $this->client->api(sprintf(
            'uuid_record %s start %s',
            $callUuid,
            $absolutePath,
        ));
    }

    public function stopRecording(string $callUuid, string $absolutePath): void
    {
        $this->client->api(sprintf(
            'uuid_record %s stop %s',
            $callUuid,
            $absolutePath,
        ));
    }
}
