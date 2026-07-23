<?php

namespace App\Contracts\Telephony;

interface FreeSwitchConferenceGateway
{
    /**
     * @return array<int, array{member_id: string, caller_number: ?string, caller_name: ?string, uuid?: ?string, conference_name?: string}>
     */
    public function listMembers(string $conferenceName): array;

    public function kickMember(string $conferenceName, string $memberId): void;

    public function muteMember(string $conferenceName, string $memberId): void;

    public function unmuteMember(string $conferenceName, string $memberId): void;

    public function videoMuteMember(string $conferenceName, string $memberId): void;

    public function videoUnmuteMember(string $conferenceName, string $memberId): void;

    /**
     * Pin the conference's video floor to the given member, overriding auto-floor switching.
     */
    public function pinVideoFloor(string $conferenceName, string $memberId): void;

    /**
     * Release a previously pinned video floor, restoring FreeSWITCH's auto-floor behavior.
     */
    public function releaseVideoFloor(string $conferenceName): void;

    public function startRecording(string $conferenceName, string $absolutePath): void;

    public function stopRecording(string $conferenceName, string $absolutePath): void;
}
