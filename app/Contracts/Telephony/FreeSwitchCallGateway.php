<?php

namespace App\Contracts\Telephony;

use App\Data\CallRecordingProfile;

interface FreeSwitchCallGateway
{
    public function announceRecordingStart(string $callUuid, string $audioPath, string $target): void;

    public function startRecording(string $callUuid, string $absolutePath, CallRecordingProfile $profile): void;

    public function stopRecording(string $callUuid, string $absolutePath, CallRecordingProfile $profile): void;

    /**
     * Safely transfer an active FreeSWITCH channel only after the destination
     * answers. A failed consultation must leave the original call intact.
     */
    public function transfer(
        string $callUuid,
        string $destination,
        string $callerNumber,
        int $ringTimeoutSeconds = 20,
    ): void;
}
