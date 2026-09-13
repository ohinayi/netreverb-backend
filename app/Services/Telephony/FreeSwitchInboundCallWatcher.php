<?php

namespace App\Services\Telephony;

use App\Models\DeviceToken;
use App\Models\Extension;
use App\Services\Push\FcmPushSender;
use Illuminate\Support\Facades\Log;

/**
 * A second, independent FreeSWITCH event-socket connection (deliberately not
 * sharing FreeSwitchCallUuidSynchronizer's loop) whose only job is: when a
 * call is being placed to an extension owned by a user with a registered
 * mobile device, fire an FCM push so the phone can ring even if the app is
 * backgrounded. Read-only — never touches call routing or CallLog rows, so
 * a bug here cannot break an actual call the way a mistake in the dialplan
 * or the UUID synchronizer could.
 */
class FreeSwitchInboundCallWatcher
{
    public function __construct(
        private readonly FreeSwitchEventSocketClient $client,
        private readonly FcmPushSender $pusher,
    ) {}

    public function watchForever(): never
    {
        $recentlyHandled = [];

        $this->client->listenForever(
            ['CHANNEL_CREATE'],
            function (array $message) use (&$recentlyHandled): void {
                $event = $this->parseEventMessage($message);

                if ($this->channelValue($event, ['event-name']) !== 'CHANNEL_CREATE') {
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

                $this->handleChannel($event);
            },
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleChannel(array $event): void
    {
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
            ->whereNotNull('user_id')
            ->first();

        if ($extension === null) {
            return;
        }

        $deviceTokens = DeviceToken::query()->where('user_id', $extension->user_id)->get();

        if ($deviceTokens->isEmpty()) {
            return;
        }

        $callerNumber = $this->channelValue($event, [
            'caller-caller-id-number',
            'variable_effective_caller_id_number',
            'variable_sip_from_user',
        ]) ?? 'Unknown';

        $callerName = $this->channelValue($event, [
            'caller-caller-id-name',
            'variable_effective_caller_id_name',
        ]) ?? $callerNumber;

        foreach ($deviceTokens as $deviceToken) {
            $sent = $this->pusher->send($deviceToken->fcm_token, [
                'type' => 'incoming_call',
                'caller_number' => $callerNumber,
                'caller_name' => $callerName,
                'callee_number' => $calleeNumber,
            ]);

            Log::info('Incoming-call push dispatched.', [
                'user_id' => $extension->user_id,
                'callee_number' => $calleeNumber,
                'caller_number' => $callerNumber,
                'sent' => $sent,
            ]);
        }
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
