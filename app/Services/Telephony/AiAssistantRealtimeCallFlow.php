<?php

namespace App\Services\Telephony;

use App\Enums\AiAssistantResponseMode;
use App\Models\AiAssistant;
use App\Models\AiAssistantSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Speech-to-speech version of the AI assistant, entered by dialing a
 * service number whose assistant has response_mode=speech_to_speech.
 *
 * mod_audio_stream only registers `uuid_audio_stream` as an ESL/CLI API
 * *command*, not a dialplan *application* - `application="api"` in dialplan
 * XML is not a real, registered application at all (confirmed live:
 * `[ERR] switch_core_session.c:2766 Invalid Application api`, which
 * FreeSWITCH treats as fatal and hangs the channel up with
 * DESTINATION_OUT_OF_ORDER immediately after answering). There is no
 * built-in dialplan application that runs an arbitrary API command against
 * the current channel either (confirmed via `fs_cli -x "show application"`).
 *
 * So this mirrors AiAssistantCallFlow's own established pattern instead:
 * emitEntry() only transfers to a second XML-cURL re-fetch
 * (handleStart()), and THAT handler - running against a channel FreeSWITCH
 * has already fully created - issues the real `uuid_audio_stream ... start`
 * command over a genuine one-shot ESL connection (the same
 * FreeSwitchEventSocketClient::api() pattern that already powers
 * transfer()/addParty()/startRecording()). From there the bridge and
 * Gemini Live own the conversation entirely - no further XML-cURL traffic
 * for this call.
 */
class AiAssistantRealtimeCallFlow
{
    public const START_CONTEXT_PREFIX = 'ai-assistant-realtime-start-';

    public function __construct(private readonly FreeSwitchEventSocketClient $client) {}

    public function emitEntry(\DOMDocument $xml, \DOMElement $condition, AiAssistant $assistant): void
    {
        $session = AiAssistantSession::query()->create([
            'organization_id' => $assistant->organization_id,
            'ai_assistant_id' => $assistant->id,
            'status' => 'in_progress',
            'mode' => AiAssistantResponseMode::SpeechToSpeech->value,
            'bridge_session_token' => (string) Str::random(40),
            'started_at' => now(),
        ]);

        // The controller has already appended `answer` before branching
        // into this class. Transfer to a fresh re-fetch rather than trying
        // to start the stream inline here - at this point in the FIRST
        // XML-cURL response, the `answer` action hasn't actually executed
        // yet (it's just been described), so there is no guarantee the
        // channel is in a state uuid_audio_stream can act on. The re-fetch
        // only happens once `answer` has actually run.
        $this->appendTransfer($xml, $condition, 'continue', self::START_CONTEXT_PREFIX.$session->public_id);
    }

    public function handleStart(Request $request, string $contextName)
    {
        $publicId = substr($contextName, strlen(self::START_CONTEXT_PREFIX));
        [$xml, $context, $condition] = $this->skeleton($contextName);
        $session = AiAssistantSession::query()->where('public_id', $publicId)->first();

        if (! $session || $session->status !== 'in_progress') {
            $this->appendHangup($xml, $condition);

            return $this->respond($xml);
        }

        $uuid = $this->resolveChannelUuid($request);

        if ($uuid === null) {
            Log::error('Could not resolve a FreeSWITCH channel UUID for a speech-to-speech AI call.', [
                'session_id' => $session->public_id,
            ]);
            $this->appendHangup($xml, $condition);

            return $this->respond($xml);
        }

        $host = (string) config('telephony.ai_assistant_realtime.bridge_host');
        $port = (int) config('telephony.ai_assistant_realtime.bridge_port');
        $wsUrl = sprintf('ws://%s:%d/session/%s', $host, $port, $session->bridge_session_token);

        // The trailing $uuid is mod_audio_stream's optional metadata
        // argument - it sends that value as the very first text message
        // over the WebSocket before any binary audio frames, which is how
        // the bridge learns this call's real FreeSWITCH channel UUID
        // (needed for its own later uuid_break on barge-in and uuid_kill at
        // the end of the call).
        $command = sprintf('uuid_audio_stream %s start %s mono 16k %s', $uuid, $wsUrl, $uuid);
        $response = trim($this->client->api($command));

        if (! str_contains($response, '+OK')) {
            Log::error('Failed to start mod_audio_stream for a speech-to-speech AI call.', [
                'session_id' => $session->public_id,
                'uuid' => $uuid,
                'response' => $response,
            ]);
            $this->appendHangup($xml, $condition);

            return $this->respond($xml);
        }

        $this->appendPark($xml, $condition);

        return $this->respond($xml);
    }

    /**
     * FreeSWITCH's XML-cURL dialplan re-fetch (triggered by `transfer`)
     * carries the caller profile as `Caller-*` POST fields, but not always
     * a bare channel UUID field under a name that's safe to assume - so
     * this tries the handful of plausible direct field names first, and
     * falls back to matching this call against a live `show channels as
     * json` snapshot by caller/callee number (the exact same matching
     * approach FreeSwitchCallUuidSynchronizer already uses, proven live in
     * production for regular call logs).
     */
    private function resolveChannelUuid(Request $request): ?string
    {
        foreach (['Unique-ID', 'Channel-Call-UUID', 'variable_uuid', 'Caller-Unique-ID', 'Channel-Unique-ID', 'uuid'] as $field) {
            $value = $request->input($field);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $callerNumber = (string) ($request->input('Caller-Caller-ID-Number') ?: $request->input('caller_id_number'));
        $calleeNumber = (string) ($request->input('Caller-Destination-Number') ?: $request->input('destination_number'));

        if ($callerNumber === '' || $calleeNumber === '') {
            return null;
        }

        $snapshot = json_decode(trim($this->client->api('show channels as json')), true);
        $rows = is_array($snapshot) ? ($snapshot['rows'] ?? []) : [];

        $best = null;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rowCaller = (string) ($row['cid_num'] ?? '');
            $rowCallee = (string) ($row['dest'] ?? '');

            if ($rowCaller !== $callerNumber || $rowCallee !== $calleeNumber) {
                continue;
            }

            if ($best === null || (float) ($row['created_epoch'] ?? 0) > (float) ($best['created_epoch'] ?? 0)) {
                $best = $row;
            }
        }

        $uuid = $best['uuid'] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    private function appendTransfer(\DOMDocument $xml, \DOMElement $condition, string $destination, string $context): void
    {
        $action = $condition->appendChild($xml->createElement('action'));
        $action->setAttribute('application', 'transfer');
        $action->setAttribute('data', $destination.' XML '.$context);
    }

    private function appendPark(\DOMDocument $xml, \DOMElement $condition): void
    {
        $action = $condition->appendChild($xml->createElement('action'));
        $action->setAttribute('application', 'park');
    }

    private function appendHangup(\DOMDocument $xml, \DOMElement $condition): void
    {
        $action = $condition->appendChild($xml->createElement('action'));
        $action->setAttribute('application', 'hangup');
        $action->setAttribute('data', 'NORMAL_CLEARING');
    }

    /** @return array{0: \DOMDocument, 1: \DOMElement, 2: \DOMElement} document, context, and a catch-all condition to append actions into */
    private function skeleton(string $contextName): array
    {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $document = $xml->appendChild($xml->createElement('document'));
        $document->setAttribute('type', 'freeswitch/xml');
        $section = $document->appendChild($xml->createElement('section'));
        $section->setAttribute('name', 'dialplan');
        $context = $section->appendChild($xml->createElement('context'));
        $context->setAttribute('name', $contextName);

        $extension = $context->appendChild($xml->createElement('extension'));
        $extension->setAttribute('name', 'continue');
        $condition = $extension->appendChild($xml->createElement('condition'));
        $condition->setAttribute('field', 'destination_number');
        $condition->setAttribute('expression', '^.*$');

        return [$xml, $context, $condition];
    }

    private function respond(\DOMDocument $xml)
    {
        return response($xml->saveXML(), 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }
}
