<?php

namespace App\Jobs;

use App\Enums\AiAssistantResponseMode;
use App\Models\AiAssistantSession;
use App\Services\Ai\AiCreditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A session only ever transitions out of 'in_progress' when the flow
 * naturally finishes all fields (AiAssistantCallFlow::finishSessionAndSayGoodbye)
 * - if the caller just hangs up mid-call, or the channel dies for any other
 * reason, nothing tells the session it's over and it sits "in progress"
 * forever. This periodically closes out anything that's gone quiet too
 * long, so the call log reflects reality instead of phantom live calls.
 */
class ExpireStaleAiAssistantSessions implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(AiCreditService $credits): void
    {
        AiAssistantSession::query()
            ->where('status', 'in_progress')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->each(function (AiAssistantSession $session) use ($credits): void {
                // A speech-to-speech call that goes stale never reaches
                // RealtimeBridgeSessionController::complete() (the bridge
                // process crashed, or the call just never reported back),
                // so duration_seconds is still 0 here - bill for the
                // elapsed time instead of nothing at all, capped at this
                // job's own 10-minute staleness threshold. Turn-based
                // sessions are never billed, this branch never runs for
                // them.
                if ($session->mode === AiAssistantResponseMode::SpeechToSpeech
                    && (int) $session->duration_seconds === 0) {
                    $elapsedSeconds = min(600, (int) $session->started_at?->diffInSeconds(now()));
                    $credits->debitForSession($session, $elapsedSeconds);
                }

                $session->forceFill([
                    'status' => 'failed',
                    'provider_metadata' => array_merge($session->provider_metadata ?? [], [
                        'error' => 'Session went stale without completing - the call likely ended without finishing the flow.',
                    ]),
                ])->save();
            });
    }
}
