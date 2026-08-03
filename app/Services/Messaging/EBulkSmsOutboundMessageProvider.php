<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\OutboundMessageProvider;
use App\Exceptions\Messaging\IndeterminateOutboundMessageException;
use App\Exceptions\Messaging\PermanentOutboundMessageException;
use App\Models\OutboundMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class EBulkSmsOutboundMessageProvider implements OutboundMessageProvider
{
    private const PERMANENT_FAILURES = [
        'INVALID_JSON',
        'MISSING_USERNAME',
        'MISSING_APIKEY',
        'AUTH_FAILURE',
        'MISSING_SENDER',
        'MISSING_MESSAGE',
        'MISSING_RECIPIENT',
        'INVALID_RECIPIENT',
        'INVALID_MESSAGE',
        'INVALID_SENDER',
        'INSUFFICIENT_CREDIT',
        'UNKNOWN_CONTENTTYPE',
    ];

    public function send(OutboundMessage $message): array
    {
        if ($message->channel !== 'sms') {
            throw new PermanentOutboundMessageException('eBulkSMS only supports SMS messages.');
        }

        $username = trim((string) config('outbound.providers.ebulksms.username'));
        $apiKey = trim((string) config('outbound.providers.ebulksms.api_key'));
        $sender = trim((string) config('outbound.providers.ebulksms.sender'));
        $endpoint = (string) config('outbound.providers.ebulksms.endpoint');
        $destination = preg_replace('/\D/', '', $message->destination) ?? '';
        $providerMessageId = $message->idempotency_key ?: $message->public_id;

        if ($username === '' || $apiKey === '' || $sender === '') {
            throw new PermanentOutboundMessageException('eBulkSMS credentials or sender ID are incomplete.');
        }
        if (! preg_match('/^(?:[A-Za-z0-9]{1,11}|\d{1,14})$/', $sender)) {
            throw new PermanentOutboundMessageException('eBulkSMS sender ID is invalid.');
        }
        if (! preg_match('/^[1-9]\d{9,14}$/', $destination)) {
            throw new PermanentOutboundMessageException('Recipient must be in full international format.');
        }
        if (mb_strlen($message->body) > 612) {
            throw new PermanentOutboundMessageException('eBulkSMS messages cannot exceed 612 characters.');
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout((int) config('outbound.providers.ebulksms.timeout', 20))
                ->post($endpoint, [
                    'SMS' => [
                        'auth' => ['username' => $username, 'apikey' => $apiKey],
                        'message' => [
                            'sender' => $sender,
                            'messagetext' => $message->body,
                            'flash' => '0',
                        ],
                        'recipients' => [
                            'gsm' => [[
                                'msidn' => $destination,
                                'msgid' => $providerMessageId,
                            ]],
                        ],
                        'dndsender' => config('outbound.providers.ebulksms.dnd_sender') ? '1' : '0',
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new IndeterminateOutboundMessageException(
                'The eBulkSMS connection ended before acceptance could be confirmed.',
                previous: $exception,
            );
        }

        if ($response->serverError()) {
            throw new IndeterminateOutboundMessageException(
                "eBulkSMS returned HTTP {$response->status()} before acceptance could be confirmed.",
            );
        }

        $status = strtoupper((string) $response->json('response.status'));
        if ($status === 'SUCCESS') {
            return ['provider' => 'ebulksms', 'message_id' => $providerMessageId];
        }
        if (in_array($status, self::PERMANENT_FAILURES, true)) {
            throw new PermanentOutboundMessageException("eBulkSMS rejected the message: {$status}.");
        }

        throw new IndeterminateOutboundMessageException(
            'eBulkSMS returned an unrecognized response; delivery must be reconciled manually.',
        );
    }
}
