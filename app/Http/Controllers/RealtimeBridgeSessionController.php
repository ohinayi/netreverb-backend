<?php

namespace App\Http\Controllers;

use App\Enums\AiAssistantResponseMode;
use App\Models\AiAssistantSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Internal-only endpoints for the separate Node.js realtime-bridge process
 * (see the netreverb-realtime-bridge repo) to fetch an AiAssistant's
 * config at the start of a speech-to-speech call, and write the outcome
 * back once it ends. Same trust model as FreeSwitchDialplanController's
 * XML-cURL endpoint: a shared token plus an IP allowlist - restricted to
 * loopback only here, since the bridge always runs on the same box as
 * FreeSWITCH and never over a network hop.
 */
class RealtimeBridgeSessionController extends Controller
{
    public function show(Request $request, string $token): JsonResponse
    {
        $this->authorizeBridgeRequest($request);

        $session = AiAssistantSession::query()
            ->with('assistant')
            ->where('bridge_session_token', $token)
            ->where('status', 'in_progress')
            ->where('mode', AiAssistantResponseMode::SpeechToSpeech->value)
            ->first();

        abort_if($session === null, Response::HTTP_NOT_FOUND);

        $assistant = $session->assistant;

        return response()->json([
            'session' => ['id' => $session->public_id],
            'assistant' => [
                'system_instruction' => $assistant->system_instruction,
                'welcome_message' => $assistant->welcome_message,
                // Fixed to English for v1 - see AiAssistantRealtimeCallFlow's
                // docblock and the project plan for why this is deliberately
                // out of scope for now, not an oversight.
                'language' => 'en',
            ],
        ]);
    }

    public function complete(Request $request, string $token): JsonResponse
    {
        $this->authorizeBridgeRequest($request);

        $data = $request->validate([
            'status' => ['required', 'in:completed,failed'],
            'transcript' => ['nullable', 'string'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'provider_metadata' => ['nullable', 'array'],
        ]);

        $session = AiAssistantSession::query()
            ->where('bridge_session_token', $token)
            ->where('mode', AiAssistantResponseMode::SpeechToSpeech->value)
            ->first();

        abort_if($session === null, Response::HTTP_NOT_FOUND);

        $session->forceFill([
            'status' => $data['status'],
            'transcript' => $data['transcript'] ?? $session->transcript,
            'duration_seconds' => $data['duration_seconds'] ?? $session->duration_seconds,
            'provider_metadata' => $data['provider_metadata'] ?? $session->provider_metadata,
            'completed_at' => now(),
        ])->save();

        Log::info('Realtime AI assistant session completed.', [
            'session_id' => $session->public_id,
            'status' => $data['status'],
            'duration_seconds' => $data['duration_seconds'] ?? null,
        ]);

        return response()->json(['ok' => true]);
    }

    private function authorizeBridgeRequest(Request $request): void
    {
        $token = (string) config('telephony.ai_assistant_realtime.bridge_token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->query('token')), Response::HTTP_FORBIDDEN);

        // The bridge calls back over LARAVEL_BASE_URL, which is the public
        // domain (needed for TLS) - even same-box, that round-trips out
        // through the public interface, so nginx sees the box's own public
        // IP as REMOTE_ADDR, not 127.0.0.1. A hardcoded loopback-only check
        // rejected every real bridge request with a 403, which is what
        // killed the call immediately after mod_audio_stream started
        // (confirmed live: bridge log showed "Failed to fetch session
        // config (HTTP 403)" right after "Call UUID", followed by the
        // bridge closing the stream and FreeSWITCH hanging up). Reusing the
        // XML-cURL allowlist here is correct, not a workaround - it already
        // solves this exact same-box-via-public-interface case for
        // FreeSwitchDialplanController.
        abort_unless(in_array($request->ip(), config('telephony.freeswitch.xml_curl_allowed_ips', []), true), Response::HTTP_FORBIDDEN);
    }
}
