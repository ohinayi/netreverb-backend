<?php

namespace App\Services\Telephony;

use App\Enums\CallRecordingStatus;
use App\Enums\CallStatus;
use App\Models\CallLog;
use App\Services\CallRecordings\CallRecordingManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FreeSwitchCallUuidSynchronizer
{
    public function __construct(
        private readonly FreeSwitchEventSocketClient $client,
        private readonly CallRecordingManager $recordingManager,
    ) {}

    public function syncOnce(int $listenSeconds = 5): int
    {
        $syncRunId = (string) Str::ulid();

        Log::withContext([
            'sync_run_id' => $syncRunId,
            'source' => 'telephony:sync-freeswitch-call-uuids',
        ]);

        Log::info('FreeSWITCH UUID sync run started.');

        $matchedCount = $this->syncFromSnapshotCommand('show channels as json');

        if ($matchedCount === 0) {
            $matchedCount = $this->syncFromSnapshotCommand('show calls as json');
        }

        if ($matchedCount === 0) {
            $eventChannels = $this->listenForChannelEvents($listenSeconds);

            Log::info('FreeSWITCH event stream parsed.', [
                'channel_count' => count($eventChannels),
            ]);

            $matchedCount += $this->processChannels($eventChannels, 'event stream');
        }

        Log::info('FreeSWITCH UUID sync run finished.', [
            'matched_count' => $matchedCount,
        ]);

        return $matchedCount;
    }

    public function syncFromEvents(int $listenSeconds = 5): int
    {
        $syncRunId = (string) Str::ulid();

        Log::withContext([
            'sync_run_id' => $syncRunId,
            'source' => 'telephony:watch-freeswitch-call-uuids',
        ]);

        Log::info('FreeSWITCH event watch run started.');

        $eventChannels = $this->listenForChannelEvents($listenSeconds);

        Log::info('FreeSWITCH event stream parsed.', [
            'channel_count' => count($eventChannels),
        ]);

        $matchedCount = $this->processChannels($eventChannels, 'event watch');

        Log::info('FreeSWITCH event watch run finished.', [
            'matched_count' => $matchedCount,
        ]);

        return $matchedCount;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractChannels(string $response): array
    {
        $decoded = json_decode($response, true);

        if (! is_array($decoded)) {
            Log::warning('FreeSWITCH channel dump could not be decoded as JSON.', [
                'response_preview' => Str::limit(preg_replace('/\s+/', ' ', $response), 500),
            ]);

            return [];
        }

        foreach (['rows', 'row', 'data', 'channels'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return array_is_list($decoded[$key]) ? $decoded[$key] : [$decoded[$key]];
            }
        }

        Log::warning('FreeSWITCH channel dump did not contain a recognized channels payload.', [
            'top_level_keys' => array_slice(array_keys($decoded), 0, 20),
        ]);

        return array_is_list($decoded) ? $decoded : [];
    }

    private function syncFromSnapshotCommand(string $command): int
    {
        $response = $this->client->api($command);

        if (trim($response) === '') {
            Log::warning('FreeSWITCH UUID sync returned an empty snapshot response.', [
                'command' => $command,
            ]);

            return 0;
        }

        Log::info('FreeSWITCH snapshot received.', [
            'command' => $command,
            'response_length' => strlen($response),
            'response_preview' => Str::limit(preg_replace('/\s+/', ' ', $response), 500),
        ]);

        $channels = $this->extractChannels($response);

        Log::info('FreeSWITCH snapshot parsed.', [
            'command' => $command,
            'channel_count' => count($channels),
        ]);

        return $this->processChannels($channels, $command);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listenForChannelEvents(int $listenSeconds = 1): array
    {
        $events = $this->client->events([
            'CHANNEL_CREATE',
            'CHANNEL_ANSWER',
            'CHANNEL_HANGUP_COMPLETE',
        ], $listenSeconds);

        Log::info('FreeSWITCH event socket response received.', [
            'event_count' => count($events),
        ]);

        $channels = [];

        foreach ($events as $index => $message) {
            if (! is_array($message)) {
                Log::warning('FreeSWITCH event message was not an array.', [
                    'event_index' => $index,
                    'payload_type' => gettype($message),
                ]);

                continue;
            }

            $event = $this->parseEventMessage($message);
            $eventName = $this->channelValue($event, ['event-name']);

            if ($eventName === null) {
                Log::warning('FreeSWITCH event payload was missing Event-Name.', [
                    'event_index' => $index,
                    'payload_keys' => array_slice(array_keys($event), 0, 20),
                ]);

                continue;
            }

            if (! in_array($eventName, ['CHANNEL_CREATE', 'CHANNEL_ANSWER', 'CHANNEL_HANGUP_COMPLETE'], true)) {
                continue;
            }

            $channels[] = $event;
        }

        return $channels;
    }

    /**
     * @param  array<int, array<string, mixed>>  $channels
     */
    private function processChannels(array $channels, string $source): int
    {
        $matchedCount = 0;

        foreach ($channels as $index => $channel) {
            if (! is_array($channel)) {
                Log::warning('FreeSWITCH channel payload was not an array.', [
                    'source' => $source,
                    'channel_index' => $index,
                    'payload_type' => gettype($channel),
                ]);

                continue;
            }

            $uuid = $this->channelValue($channel, ['uuid', 'Unique-ID', 'unique_id']);
            $callerNumber = $this->channelValue($channel, [
                'caller_id_number',
                'Caller-Caller-ID-Number',
                'variable_effective_caller_id_number',
                'variable_sip_from_user',
                'variable_caller_id_number',
            ]);
            $calleeNumber = $this->channelValue($channel, [
                'destination_number',
                'Caller-Destination-Number',
                'variable_sip_req_user',
                'variable_sip_to_user',
                'variable_called_user',
            ]);

            if ($uuid === null || $callerNumber === null || $calleeNumber === null) {
                Log::warning('FreeSWITCH channel is missing UUID or dialed numbers.', [
                    'source' => $source,
                    'channel_index' => $index,
                    'channel_keys' => array_slice(array_keys($channel), 0, 20),
                    'uuid' => $uuid,
                    'caller_number' => $callerNumber,
                    'callee_number' => $calleeNumber,
                ]);

                continue;
            }

            Log::debug('Inspecting FreeSWITCH channel for call-log match.', [
                'source' => $source,
                'channel_index' => $index,
                'uuid' => $uuid,
                'caller_number' => $callerNumber,
                'callee_number' => $calleeNumber,
            ]);

            $callLog = $this->resolveCallLog($callerNumber, $calleeNumber);

            if ($callLog === null) {
                Log::warning('No call log matched the FreeSWITCH channel.', [
                    'source' => $source,
                    'channel_index' => $index,
                    'uuid' => $uuid,
                    'caller_number' => $callerNumber,
                    'callee_number' => $calleeNumber,
                ]);

                continue;
            }

            if ($callLog->freeswitch_uuid === null) {
                $callLog->forceFill([
                    'freeswitch_uuid' => $uuid,
                    'status' => $callLog->status === CallStatus::Ringing
                        ? CallStatus::InProgress
                        : $callLog->status,
                ])->save();

                Log::info('FreeSWITCH call UUID synced.', [
                    'source' => $source,
                    'call_log_id' => $callLog->public_id,
                    'freeswitch_uuid' => $uuid,
                    'caller_number' => $callerNumber,
                    'callee_number' => $calleeNumber,
                ]);
            } elseif ($callLog->freeswitch_uuid !== $uuid) {
                Log::warning('Matched call log already has a different FreeSWITCH UUID.', [
                    'source' => $source,
                    'call_log_id' => $callLog->public_id,
                    'existing_freeswitch_uuid' => $callLog->freeswitch_uuid,
                    'channel_uuid' => $uuid,
                    'caller_number' => $callerNumber,
                    'callee_number' => $calleeNumber,
                ]);
            }

            if ($this->shouldAutoStartRecording($callLog)) {
                Log::info('Auto-starting call recording for synced FreeSWITCH UUID.', [
                    'source' => $source,
                    'call_log_id' => $callLog->public_id,
                    'freeswitch_uuid' => $uuid,
                ]);

                $this->recordingManager->start($callLog->refresh(), $uuid);
            }

            $matchedCount++;
        }

        return $matchedCount;
    }

    /**
     * @return array<string, string>
     */
    private function parseEventBody(string $body): array
    {
        $payload = [];

        foreach (preg_split('/\r?\n/', trim($body)) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = array_map('trim', explode(':', $line, 2));
            $payload[strtolower($name)] = $value;
        }

        return $payload;
    }

    /**
     * @param  array{headers?: array<string, string>, body?: string}  $message
     * @return array<string, mixed>
     */
    private function parseEventMessage(array $message): array
    {
        $headers = [];

        foreach ($message['headers'] ?? [] as $headerName => $headerValue) {
            $headers[$this->normalizeKey((string) $headerName)] = $headerValue;
        }

        return array_merge(
            $headers,
            $this->parseEventBody($message['body'] ?? ''),
        );
    }

    /**
     * @param  array<string, mixed>  $channel
     */
    private function channelValue(array $channel, array $keys): ?string
    {
        $normalizedChannel = [];

        foreach ($channel as $channelKey => $value) {
            $normalizedChannel[$this->normalizeKey((string) $channelKey)] = $value;
        }

        foreach ($keys as $key) {
            $normalizedKey = $this->normalizeKey($key);

            if (! array_key_exists($normalizedKey, $normalizedChannel)) {
                continue;
            }

            $value = $normalizedChannel[$normalizedKey];

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function resolveCallLog(string $callerNumber, string $calleeNumber): ?CallLog
    {
        $cutoff = now()->subMinutes(30);
        $callerVariants = $this->numberVariants($callerNumber);
        $calleeVariants = $this->numberVariants($calleeNumber);

        return CallLog::query()
            ->whereNull('deleted_at')
            ->whereNull('freeswitch_uuid')
            ->where('created_at', '>=', $cutoff)
            ->where(function ($query) use ($callerVariants, $calleeVariants): void {
                $query->where(function ($query) use ($callerVariants, $calleeVariants): void {
                    $query->whereIn('caller_number', $callerVariants)
                        ->whereIn('callee_number', $calleeVariants);
                })->orWhere(function ($query) use ($callerVariants, $calleeVariants): void {
                    $query->whereIn('caller_number', $calleeVariants)
                        ->whereIn('callee_number', $callerVariants);
                });
            })
            ->latest('id')
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function numberVariants(string $number): array
    {
        $variants = [];
        $trimmed = trim($number);

        if ($trimmed === '') {
            return [];
        }

        $variants[] = $trimmed;
        $variants[] = ltrim($trimmed, '+');
        $variants[] = preg_replace('/^vb-/', '', $trimmed) ?? $trimmed;
        $variants[] = preg_replace('/^sofia\/[^\/]+\//', '', $trimmed) ?? $trimmed;
        $variants[] = preg_replace('/\D+/', '', $trimmed) ?? $trimmed;

        $variants = array_values(array_unique(array_filter($variants, static fn (string $value): bool => $value !== '')));

        return $variants;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(str_replace(['-', ' '], '_', trim($key)));
    }

    private function shouldAutoStartRecording(CallLog $callLog): bool
    {
        return $callLog->recording_status === null
            || $callLog->recording_status === CallRecordingStatus::Failed;
    }
}
