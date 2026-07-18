<?php

namespace App\Services\Telephony;

use App\Contracts\Telephony\FreeSwitchConferenceGateway;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;

class SocketFreeSwitchConferenceGateway implements FreeSwitchConferenceGateway
{
    public function __construct(private readonly FreeSwitchEventSocketClient $client) {}

    public function listMembers(string $conferenceName): array
    {
        $members = $this->listMembersForConferenceName($conferenceName);

        if ($members !== []) {
            return $members;
        }

        $conferenceChannels = $this->conferenceChannels($conferenceName);

        foreach ($this->discoverConferenceNames($conferenceName, $conferenceChannels) as $discoveredConferenceName) {
            $members = $this->listMembersForConferenceName($discoveredConferenceName);

            if ($members !== []) {
                return $members;
            }
        }

        return $this->listMembersFromChannels($conferenceName, $conferenceChannels);
    }

    public function kickMember(string $conferenceName, string $memberId): void
    {
        $this->runConferenceCommand($conferenceName, sprintf('kick %s', $memberId));
    }

    public function muteMember(string $conferenceName, string $memberId): void
    {
        $this->runConferenceCommand($conferenceName, sprintf('mute %s quiet', $memberId));
    }

    public function unmuteMember(string $conferenceName, string $memberId): void
    {
        $this->runConferenceCommand($conferenceName, sprintf('unmute %s quiet', $memberId));
    }

    public function videoMuteMember(string $conferenceName, string $memberId): void
    {
        $this->runConferenceCommand($conferenceName, sprintf('vmute %s quiet', $memberId));
    }

    public function videoUnmuteMember(string $conferenceName, string $memberId): void
    {
        $this->runConferenceCommand($conferenceName, sprintf('unvmute %s quiet', $memberId));
    }

    public function startRecording(string $conferenceName, string $absolutePath): void
    {
        $this->runConferenceCommand($conferenceName, sprintf('recording start %s', $absolutePath));
    }

    public function stopRecording(string $conferenceName, string $absolutePath): void
    {
        $this->runConferenceCommand($conferenceName, sprintf('recording stop %s', $absolutePath));
    }

    /**
     * @return array<int, array{member_id: string, caller_number: ?string, caller_name: ?string, uuid?: ?string, conference_name?: string}>
     */
    private function listMembersForConferenceName(string $conferenceName): array
    {
        $response = trim($this->client->api(sprintf('conference %s xml_list', $conferenceName)));

        if ($response === '' || str_contains(strtolower($response), 'not found')) {
            return [];
        }

        try {
            $xml = new SimpleXMLElement($response);
        } catch (\Throwable $throwable) {
            throw new RuntimeException('Unable to parse FreeSWITCH conference member list.', previous: $throwable);
        }

        $members = $xml->xpath('//member') ?: [];

        return array_values(array_map(function (SimpleXMLElement $member) use ($conferenceName): array {
            return [
                'member_id' => $this->xmlNodeValue($member, ['id']) ?? (string) ($member['id'] ?? ''),
                'caller_number' => $this->xmlNodeValue($member, ['caller_id_number']),
                'caller_name' => $this->xmlNodeValue($member, ['caller_id_name', 'name']),
                'uuid' => $this->xmlNodeValue($member, ['uuid']),
                'conference_name' => $conferenceName,
            ];
        }, $members));
    }

    private function runConferenceCommand(string $conferenceName, string $command): void
    {
        $conferenceNames = [$conferenceName, ...$this->discoverConferenceNames($conferenceName)];

        foreach (array_values(array_unique($conferenceNames)) as $resolvedConferenceName) {
            $response = trim($this->client->api(sprintf(
                'conference %s %s',
                $resolvedConferenceName,
                $command,
            )));

            if (! str_contains(strtolower($response), 'not found')) {
                return;
            }
        }
    }

    /**
     * @return array<int, array{member_id: string, caller_number: ?string, caller_name: ?string, uuid?: ?string, conference_name?: string}>
     */
    private function listMembersFromChannels(string $conferenceName, ?array $channels = null): array
    {
        $channels ??= $this->conferenceChannels($conferenceName);

        return array_values(array_map(function (array $channel) use ($conferenceName): array {
            $memberId = $channel['member_id']
                ?? $channel['conference_member_id']
                ?? $channel['conference_member']
                ?? '';

            return [
                'member_id' => is_scalar($memberId) ? trim((string) $memberId) : '',
                'caller_number' => $this->channelValue($channel, [
                    'caller_id_number',
                    'cid_num',
                    'caller_number',
                ]),
                'caller_name' => $this->channelValue($channel, [
                    'caller_id_name',
                    'cid_name',
                    'caller_name',
                ]),
                'uuid' => $this->channelValue($channel, ['uuid']),
                'conference_name' => $this->channelValue($channel, ['application_data']) ?? $conferenceName,
            ];
        }, $channels));
    }

    /**
     * @return array<int, string>
     */
    private function discoverConferenceNames(string $conferenceName, ?array $channels = null): array
    {
        $channels ??= $this->conferenceChannels($conferenceName);

        return array_values(array_unique(array_values(array_filter(array_map(
            function (array $channel) use ($conferenceName): ?string {
                $applicationData = $this->channelValue($channel, ['application_data']);

                if (! is_string($applicationData) || ! Str::startsWith($applicationData, $conferenceName)) {
                    return null;
                }

                return $this->normalizeConferenceName($applicationData);
            },
            $channels,
        ), static fn (?string $resolvedConferenceName): bool => is_string($resolvedConferenceName) && $resolvedConferenceName !== ''))));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function conferenceChannels(string $conferenceName): array
    {
        $response = trim($this->client->api('show channels as json'));

        if ($response === '') {
            return [];
        }

        $decoded = json_decode($response, true);

        if (! is_array($decoded)) {
            return [];
        }

        $channels = $decoded['rows'] ?? $decoded['row'] ?? $decoded['channels'] ?? [];

        if (! is_array($channels)) {
            return [];
        }

        if (! array_is_list($channels)) {
            $channels = [$channels];
        }

        return array_values(array_filter($channels, function (mixed $channel) use ($conferenceName): bool {
            if (! is_array($channel)) {
                return false;
            }

            $destinationNumber = $this->channelValue($channel, [
                'dest',
                'destination_number',
                'Caller-Destination-Number',
            ]);
            $application = $this->channelValue($channel, ['application']);
            $applicationData = $this->channelValue($channel, ['application_data']);

            return $destinationNumber === $conferenceName
                && $application === 'conference'
                && is_string($applicationData)
                && Str::startsWith($applicationData, $conferenceName);
        }));
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function channelValue(array $channel, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $channel[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $names
     */
    private function xmlNodeValue(SimpleXMLElement $member, array $names): ?string
    {
        foreach ($names as $name) {
            if (isset($member->{$name}) && trim((string) $member->{$name}) !== '') {
                return trim((string) $member->{$name});
            }
        }

        return null;
    }

    private function normalizeConferenceName(string $conferenceName): string
    {
        $normalizedConferenceName = trim($conferenceName);

        if ($normalizedConferenceName === '') {
            return $normalizedConferenceName;
        }

        return preg_replace('/@[^@]+$/', '', $normalizedConferenceName) ?: $normalizedConferenceName;
    }
}
