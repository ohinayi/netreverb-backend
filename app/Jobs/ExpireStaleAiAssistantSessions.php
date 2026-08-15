<?php

namespace App\Jobs;

use App\Models\AiAssistantSession;
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

    public function handle(): void
    {
        AiAssistantSession::query()
            ->where('status', 'in_progress')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->each(function (AiAssistantSession $session): void {
                $session->forceFill([
                    'status' => 'failed',
                    'provider_metadata' => array_merge($session->provider_metadata ?? [], [
                        'error' => 'Session went stale without completing - the call likely ended without finishing the flow.',
                    ]),
                ])->save();
            });
    }
}
