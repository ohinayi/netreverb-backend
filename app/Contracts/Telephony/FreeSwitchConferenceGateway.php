<?php

namespace App\Contracts\Telephony;

interface FreeSwitchConferenceGateway
{
    public function startRecording(string $conferenceName, string $absolutePath): void;

    public function stopRecording(string $conferenceName, string $absolutePath): void;
}
