<?php

namespace App\Services\Telephony;

use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Enums\CallStatus;
use App\Enums\ConferenceParticipantKind;
use App\Models\CallLog;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;

class ConferenceLiveMemberResolver
{
    /**
     * Suffix appended to a screen-share leg's SIP display name so live FreeSWITCH
     * members can be disambiguated from the same user's primary (camera/audio) leg.
     */
    public const SCREEN_SHARE_CALLER_NAME_SUFFIX = ' (Screen)';

    /**
     * @return array<int, array{member_id: string, caller_number: ?string, caller_name: ?string, uuid?: ?string}>
     */
    public function membersForRoom(ConferenceRoom $conferenceRoom): array
    {
        return app(FreeSwitchConferenceGateway::class)
            ->listMembers($conferenceRoom->sip_number);
    }

    /**
     * @param  array<int, array{member_id: string, caller_number: ?string, caller_name: ?string, uuid?: ?string}>|null  $members
     * @return array{member_id: string, caller_number: ?string, caller_name: ?string, uuid?: ?string}|null
     */
    public function findMemberForParticipant(
        ConferenceRoomParticipant $participant,
        ?array $members = null,
    ): ?array {
        return $this->findMembersForParticipant($participant, $members)[0] ?? null;
    }

    /**
     * @param  array<int, array{member_id: string, caller_number: ?string, caller_name: ?string, uuid?: ?string}>|null  $members
     * @return array<int, array{member_id: string, caller_number: ?string, caller_name: ?string, uuid?: ?string}>
     */
    public function findMembersForParticipant(
        ConferenceRoomParticipant $participant,
        ?array $members = null,
    ): array {
        $members ??= $this->membersForRoom($participant->conferenceRoom);

        return array_values(array_filter(
            $members,
            fn (array $member): bool => $this->matchesParticipant($participant, $member),
        ));
    }

    /**
     * @param  array{member_id: string, caller_number: ?string, caller_name: ?string, uuid?: ?string}  $member
     */
    public function matchesParticipant(ConferenceRoomParticipant $participant, array $member): bool
    {
        $memberIsScreenShareTagged = $this->memberIsScreenShareTagged($member);

        if ($participant->kind === ConferenceParticipantKind::ScreenShare) {
            if (! $memberIsScreenShareTagged) {
                return false;
            }

            $memberCallerName = is_string($member['caller_name'] ?? null)
                ? trim((string) $member['caller_name'])
                : '';

            return $memberCallerName !== '' && $memberCallerName === trim((string) $participant->display_name);
        }

        if ($memberIsScreenShareTagged) {
            return false;
        }

        $expectedNumbers = $participant->user?->extensions
            ?->pluck('dialableNumber.number')
            ->filter(static fn (?string $number): bool => is_string($number) && $number !== '')
            ->values()
            ->all() ?? [];
        $expectedUuids = $this->expectedConferenceCallUuids($participant, $expectedNumbers);

        $expectedNames = array_values(array_filter([
            $participant->display_name,
            $participant->user?->name,
            $participant->email,
            $participant->user?->email,
        ], static fn (?string $value): bool => is_string($value) && trim($value) !== ''));

        $matchesByUuid = in_array($member['uuid'] ?? null, $expectedUuids, true);
        $matchesByName = in_array($member['caller_name'], $expectedNames, true);
        $matchesByNumber = in_array($member['caller_number'], $expectedNumbers, true);

        if ($matchesByUuid || $matchesByName) {
            return true;
        }

        if (! $matchesByNumber) {
            return false;
        }

        $memberCallerName = is_string($member['caller_name'] ?? null)
            ? trim((string) $member['caller_name'])
            : '';

        if ($memberCallerName !== '' && $expectedNames !== []) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{caller_name?: ?string}  $member
     */
    private function memberIsScreenShareTagged(array $member): bool
    {
        $callerName = is_string($member['caller_name'] ?? null) ? trim((string) $member['caller_name']) : '';

        return $callerName !== '' && str_ends_with($callerName, self::SCREEN_SHARE_CALLER_NAME_SUFFIX);
    }

    /**
     * @param  array<int, string>  $expectedNumbers
     * @return array<int, string>
     */
    private function expectedConferenceCallUuids(
        ConferenceRoomParticipant $participant,
        array $expectedNumbers,
    ): array {
        if ($expectedNumbers === []) {
            return [];
        }

        $participant->loadMissing('conferenceRoom');

        return CallLog::query()
            ->whereIn('caller_number', $expectedNumbers)
            ->where('callee_number', $participant->conferenceRoom->sip_number)
            ->whereIn('status', [CallStatus::Ringing->value, CallStatus::InProgress->value])
            ->whereNotNull('freeswitch_uuid')
            ->orderByDesc('id')
            ->limit(10)
            ->pluck('freeswitch_uuid')
            ->filter(static fn (?string $uuid): bool => is_string($uuid) && trim($uuid) !== '')
            ->values()
            ->all();
    }
}
