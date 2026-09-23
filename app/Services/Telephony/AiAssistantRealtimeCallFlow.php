<?php

namespace App\Services\Telephony;

use App\Enums\AiAssistantResponseMode;
use App\Models\AiAssistant;
use App\Models\AiAssistantSession;
use Illuminate\Support\Str;

/**
 * Speech-to-speech version of the AI assistant, entered by dialing a
 * service number whose assistant has response_mode=speech_to_speech.
 * Unlike AiAssistantCallFlow (turn-based: record -> transcribe -> extract
 * -> confirm, one XML-cURL re-fetch per turn), this class only ever runs
 * ONCE per call: it starts FreeSWITCH's mod_audio_stream flowing to a
 * separate Node.js bridge process (see the netreverb-realtime-bridge repo)
 * and then parks the channel. From that point on the bridge and Gemini
 * Live own the conversation entirely - there is no further XML-cURL
 * traffic for this call, and this class is never called again for it.
 */
class AiAssistantRealtimeCallFlow
{
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
        // into either call-flow class - mod_audio_stream requires an
        // already-answered channel, so nothing to do here for that part.
        $this->appendStartStream($xml, $condition, $session);
        $this->appendPark($xml, $condition);
    }

    /**
     * FreeSWITCH's own `api` dialplan application runs the exact same
     * uuid_audio_stream command as if it came over ESL, with ${uuid}
     * resolved to this channel's real UUID at execution time - no PHP-side
     * ESL round-trip needed just to start the stream.
     */
    private function appendStartStream(\DOMDocument $xml, \DOMElement $condition, AiAssistantSession $session): void
    {
        $host = (string) config('telephony.ai_assistant_realtime.bridge_host');
        $port = (int) config('telephony.ai_assistant_realtime.bridge_port');
        $wsUrl = sprintf('ws://%s:%d/session/%s', $host, $port, $session->bridge_session_token);

        $action = $condition->appendChild($xml->createElement('action'));
        $action->setAttribute('application', 'api');
        $action->setAttribute('data', sprintf('uuid_audio_stream ${uuid} start %s mono 16k', $wsUrl));
    }

    /**
     * Keeps the channel alive with nothing further for the dialplan to do -
     * the bridge (via its own short-lived ESL connection) plays audio back
     * with uuid_broadcast and ends the call with uuid_kill once the
     * conversation is over.
     */
    private function appendPark(\DOMDocument $xml, \DOMElement $condition): void
    {
        $action = $condition->appendChild($xml->createElement('action'));
        $action->setAttribute('application', 'park');
    }
}
