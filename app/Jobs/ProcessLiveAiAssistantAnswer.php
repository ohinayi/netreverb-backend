<?php

namespace App\Jobs;

use App\Models\AiAssistantSession;
use App\Services\Telephony\AiAssistantCallFlow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Moves the slow part of a live AI-assistant turn (transcribe the caller's
 * recorded answer, then extract the structured field value) off the
 * XML-cURL request/response cycle entirely - FreeSwitchDialplanController
 * has to return some XML before FreeSWITCH will play anything at all, so
 * doing this work inline meant total silence on the call for however long
 * transcription+extraction took. AiAssistantCallFlow::handleAnswer() polls
 * this job's progress via answer_processing_started_at/answer_ready_at,
 * playing a short wait tone on each re-check instead of dead air.
 */
class ProcessLiveAiAssistantAnswer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(private readonly int $sessionId) {}

    public function handle(AiAssistantCallFlow $callFlow): void
    {
        $session = AiAssistantSession::query()->with('assistant.fields')->find($this->sessionId);

        if (! $session || $session->status !== 'in_progress') {
            return;
        }

        $callFlow->processAnswerInBackground($session);
    }
}
