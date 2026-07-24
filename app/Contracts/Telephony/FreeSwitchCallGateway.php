<?php

namespace App\Contracts\Telephony;

use App\Data\CallRecordingProfile;

interface FreeSwitchCallGateway
{
    public function announceRecordingStart(string $callUuid, string $audioPath, string $target): void;

    public function startRecording(string $callUuid, string $absolutePath, CallRecordingProfile $profile): void;

    public function stopRecording(string $callUuid, string $absolutePath, CallRecordingProfile $profile): void;

    /** Transfer an active FreeSWITCH channel to a dialplan destination. */
    public function transfer(string $callUuid, string $destination): void;
}
