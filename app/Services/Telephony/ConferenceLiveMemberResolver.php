<?php

namespace App\Services\Telephony;

use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use App\Enums\CallStatus;
use App\Models\CallLog;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;

class ConferenceLiveMemberResolver
{
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

        return in_array($member['caller_number'], $expectedNumbers, true)
            || in_array($member['uuid'] ?? null, $expectedUuids, true)
            || in_array($member['caller_name'], $expectedNames, true);
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
