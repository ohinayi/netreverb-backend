<?php

namespace App\Jobs;

use App\Models\OrganizationIvr;
use App\Services\Telephony\IvrPromptSynthesizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs Piper TTS generation for one IVR's welcome prompt and directive
 * options off the request cycle. A save that touches a whole nested menu
 * tree dispatches one of these per node - running them inline blocked the
 * HTTP response on a chain of subprocess calls long enough to trip the
 * frontend's request timeout. Until this runs, the dialplan falls back to
 * live flite speech for anything not yet cached, so calls are never
 * broken while a prompt is pending.
 */
class SynthesizeIvrPrompts implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $organizationIvrId)
    {
        $this->onQueue('telephony');
    }

    public function handle(IvrPromptSynthesizer $synthesizer): void
    {
        $ivr = OrganizationIvr::query()->find($this->organizationIvrId);
        if (! $ivr) return;
        $synthesizer->synthesizeWelcome($ivr);
        $synthesizer->synthesizeDirectives($ivr);
    }
}
