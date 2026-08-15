<?php

namespace App\Jobs;

use App\Models\AiAssistant;
use App\Services\Ai\AiAssistantPromptSynthesizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs Piper TTS generation for an assistant's field questions off the
 * request cycle - same reasoning as SynthesizeIvrPrompts: a subprocess
 * call per question shouldn't block the admin's save request.
 */
class SynthesizeAiAssistantQuestions implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $aiAssistantId)
    {
        $this->onQueue('ai');
    }

    public function handle(AiAssistantPromptSynthesizer $synthesizer): void
    {
        $assistant = AiAssistant::query()->with('fields')->find($this->aiAssistantId);
        if (! $assistant) return;
        $synthesizer->synthesizeAll($assistant);
    }
}
