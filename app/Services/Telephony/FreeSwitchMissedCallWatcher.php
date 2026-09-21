<?php

namespace App\Services\Telephony;

use App\Enums\CallStatus;
use App\Models\CallLog;
use App\Models\Extension;
use Illuminate\Support\Facades\Log;

/**
 * A third, independent FreeSWITCH event-socket connection (deliberately not
 * sharing FreeSwitchCallUuidSynchronizer's or FreeSwitchInboundCallWatcher's
 * loop). Every call_logs row today is written by the CALLING browser tab
 * itself (see stores/calling.ts) - if the callee's tab was never open to see
 * the incoming INVITE at all (app closed, not just "didn't answer in time"),
 * no row is ever created for them and there is nothing to show as a missed
 * call when they come back online later. This listens for the real,
 * authoritative FreeSWITCH outcome of every leg addressed to one of our own
 * extensions and backfills a missed-call row only when nothing already
 * covers it - the common case (callee's tab was open, saw it ring, just
 * didn't answer) already has its own row written the normal way, and this
 * only updates that row rather than duplicating it.
 */
class FreeSwitchMissedCallWatcher
{
    /**
     * Hangup causes that mean a leg to our own extension never got answered.
     * LOSE_RACE (a sibling device/branch answered first) and NORMAL_CLEARING
     * (answered, then ended normally) are deliberately excluded - neither is
     * a missed call.
     */
    private const UNANSWERED_CAUSES = [
        'NO_ANSWER',
        'NO_USER_RESPONSE',
        'USER_BUSY',
        'ORIGINATOR_CANCEL',
        'UNALLOCATED_NUMBER',
        'CALL_REJECTED',
        'NO_ROUTE_DESTINATION',
    ];

    public function __construct(private readonly FreeSwitchEventSocketClient $client) {}

    public function watchForever(): never
    {
        $recentlyHandled = [];

        $this->client->listenForever(
            ['CHANNEL_HANGUP_COMPLETE'],
            function (array $message) use (&$recentlyHandled): void {
                $event = $this->parseEventMessage($message);

                if ($this->channelValue($event, ['event-name']) !== 'CHANNEL_HANGUP_COMPLETE') {
                    return;
                }

                $uuid = $this->channelValue($event, ['uuid', 'unique-id', 'unique_id']);

                if ($uuid !== null) {
                    if (isset($recentlyHandled[$uuid])) {
                        return;
                    }

                    $recentlyHandled[$uuid] = time();

                    if (count($recentlyHandled) > 2000) {
                        $cutoff = time() - 300;
                        $recentlyHandled = array_filter($recentlyHandled, fn (int $seenAt): bool => $seenAt >= $cutoff);
                    }
                }

                try {
                    $this->handleHangup($event, $uuid);
                } catch (\Throwable $exception) {
                    Log::error('Missed-call watcher failed to process a hangup event.', [
                        'uuid' => $uuid,
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                }
            },
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleHangup(array $event, ?string $uuid): void
    {
        $hangupCause = strtoupper((string) ($this->channelValue($event, [
            'hangup-cause',
            'variable_hangup_cause',
            'variable_originate_disposition',
        ]) ?? ''));

        if (! in_array($hangupCause, self::UNANSWERED_CAUSES, true)) {
            return;
        }

        $calleeNumber = $this->channelValue($event, [
            'caller-destination-number',
            'variable_sip_req_user',
            'variable_sip_to_user',
            'variable_called_user',
            'destination-number',
        ]);

        if ($calleeNumber === null) {
            return;
        }

        $extension = Extension::query()
            ->whereHas('dialableNumber', fn ($query) => $query->where('number', $calleeNumber))
            ->first();

        if ($extension === null) {
            // Not a call to one of our own extensions (e.g. an external PSTN
            // leg or a media-service number) - nothing for us to record.
            return;
        }

        $callerNumber = $this->channelValue($event, [
            'caller-caller-id-number',
            'variable_effective_caller_id_number',
            'variable_sip_from_user',
        ]) ?? 'Unknown';

        if ($this->digitsOnly($callerNumber) !== '' && $this->digitsOnly($callerNumber) === $this->digitsOnly($calleeNumber)) {
            return;
        }

        $status = match ($hangupCause) {
            'USER_BUSY' => CallStatus::Busy,
            'ORIGINATOR_CANCEL' => CallStatus::Canceled,
            default => CallStatus::NoAnswer,
        };

        $existing = $this->findExistingCallLog($extension, $callerNumber, $calleeNumber);

        if ($existing !== null) {
            if (in_array($existing->status, [CallStatus::Ringing, CallStatus::InProgress], true)) {
                $existing->forceFill([
                    'status' => $status,
                    'ended_at' => $existing->ended_at ?? now(),
                ])->save();

                Log::info('Missed-call watcher finalized an existing call log.', [
                    'call_log_id' => $existing->public_id,
                    'status' => $status->value,
                    'hangup_cause' => $hangupCause,
                ]);
            }

            return;
        }

        $callLog = CallLog::query()->create([
            'organization_id' => $extension->organization_id,
            'callee_extension_id' => $extension->id,
            'caller_number' => $callerNumber,
            'callee_number' => $calleeNumber,
            'status' => $status,
            'freeswitch_uuid' => $uuid,
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        Log::info('Missed-call watcher created a call log for an offline/unanswered extension.', [
            'call_log_id' => $callLog->public_id,
            'organization_id' => $extension->organization_id,
            'callee_extension_id' => $extension->id,
            'status' => $status->value,
            'hangup_cause' => $hangupCause,
        ]);
    }

    private function findExistingCallLog(Extension $extension, string $callerNumber, string $calleeNumber): ?CallLog
    {
        $callerDigits = $this->digitsOnly($callerNumber);
        $cutoff = now()->subMinutes(10);

        // Matched in PHP rather than a DB-engine-specific regex function - the
        // candidate set is always small (one extension, last 10 minutes).
        return CallLog::query()
            ->whereNull('deleted_at')
            ->where('callee_extension_id', $extension->id)
            ->where('created_at', '>=', $cutoff)
            ->latest('id')
            ->get()
            ->first(fn (CallLog $callLog): bool => $callerDigits === '' || $this->digitsOnly((string) $callLog->caller_number) === $callerDigits);
    }

    private function digitsOnly(string $number): string
    {
        return preg_replace('/\D+/', '', $number) ?? '';
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

        return array_merge($headers, $this->parseEventBody($message['body'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $channel
     * @param  array<int, string>  $keys
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

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(str_replace(['-', ' '], '_', trim($key)));
    }
}
