<?php

namespace App\Services\Telephony;

use App\Contracts\Telephony\FreeSwitchConferenceGateway;

class SocketFreeSwitchConferenceGateway implements FreeSwitchConferenceGateway
{
    public function __construct(private readonly FreeSwitchEventSocketClient $client) {}

    public function startRecording(string $conferenceName, string $absolutePath): void
    {
        $this->client->api(sprintf(
            'conference %s recording start %s',
            $conferenceName,
            $absolutePath,
        ));
    }

    public function stopRecording(string $conferenceName, string $absolutePath): void
    {
        $this->client->api(sprintf(
            'conference %s recording stop %s',
            $conferenceName,
            $absolutePath,
        ));
    }
}
