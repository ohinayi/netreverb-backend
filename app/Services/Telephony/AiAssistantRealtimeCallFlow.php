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
     * uuid_audio_stream command as if it came over ESL - but, confirmed
     * live via the FreeSWITCH log during the very first real test call,
     * `api` does NOT expand ${uuid}/channel variables in its data string
     * the way most other dialplan applications do (the log showed the
     * literal, unexpanded string "${uuid}" sent to uuid_audio_stream,
     * which is why that first live test produced no audio at all - the
     * stream never started). The documented fix is the `expand:` prefix,
     * which tells `api` to run channel-variable substitution first.
     */
    private function appendStartStream(\DOMDocument $xml, \DOMElement $condition, AiAssistantSession $session): void
    {
        $host = (string) config('telephony.ai_assistant_realtime.bridge_host');
        $port = (int) config('telephony.ai_assistant_realtime.bridge_port');
        $wsUrl = sprintf('ws://%s:%d/session/%s', $host, $port, $session->bridge_session_token);

        // The trailing ${uuid} is mod_audio_stream's optional metadata
        // argument - it sends that value as the very first text message
        // over the WebSocket before any binary audio frames, which is the
        // only way the bridge learns this call's real FreeSWITCH channel
        // UUID (needed for uuid_break on barge-in and uuid_kill at the end
        // - neither is available to this PHP class, since ${uuid} only
        // resolves inside FreeSWITCH itself when it executes this action).
        $action = $condition->appendChild($xml->createElement('action'));
        $action->setAttribute('application', 'api');
        $action->setAttribute('data', sprintf('expand:uuid_audio_stream ${uuid} start %s mono 16k ${uuid}', $wsUrl));
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
