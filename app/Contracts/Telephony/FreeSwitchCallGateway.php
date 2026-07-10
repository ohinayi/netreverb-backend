<?php

namespace App\Contracts\Telephony;

interface FreeSwitchCallGateway
{
    public function announceRecordingStart(string $callUuid, string $audioPath, string $target): void;

    public function startRecording(string $callUuid, string $absolutePath): void;

    public function stopRecording(string $callUuid, string $absolutePath): void;
}
