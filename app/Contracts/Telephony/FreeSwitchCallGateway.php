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

    /**
     * Ring a new destination and, once it answers, move the original call's
     * two existing legs plus the new leg into a shared ad-hoc conference.
     * Never SIP-holds the original parties; if the destination doesn't
     * answer, the original call is left completely untouched. Returns the
     * new leg's FreeSWITCH channel UUID.
     */
    public function addParty(
        string $callUuid,
        string $destination,
        string $callerNumber,
        string $conferenceName,
        int $ringTimeoutSeconds = 20,
    ): string;
}
