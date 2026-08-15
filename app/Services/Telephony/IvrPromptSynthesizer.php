<?php

namespace App\Services\Telephony;

use App\Models\OrganizationIvr;
use Illuminate\Support\Facades\Storage;

class IvrPromptSynthesizer
{
    public function __construct(private readonly PiperTtsService $piper) {}

    /**
     * Generates (or clears) the welcome prompt's cached audio, including
     * the enabled menu choices, and points the IVR at it.
     */
    public function synthesizeWelcome(OrganizationIvr $ivr): void
    {
        // Uploaded audio always wins. Generated Piper files are identified by
        // their deterministic path and may be safely regenerated when the
        // welcome text or menu options are edited.
        $generatedPath = 'ivr-welcome/'.$ivr->organization->public_id.'/'.$ivr->public_id.'.wav';
        $hasGeneratedAudio = $ivr->welcome_audio_path === $generatedPath;
        if ($ivr->welcome_audio_path && ! $hasGeneratedAudio) return;
        if (! $ivr->welcome_text) {
            if ($hasGeneratedAudio) {
                Storage::disk('public')->delete($generatedPath);
                $ivr->update(['welcome_audio_path' => null]);
            }
            return;
        }
        $menu = $ivr->options()->where('enabled', true)->orderBy('sort_order')->get()
            ->map(fn ($option): string => 'Press '.$option->digit.' for '.$option->label.'.')
            ->implode(' ');
        $text = trim($ivr->welcome_text.'. '.$menu);
        $generated = $this->piper->generate($text, $generatedPath, $ivr->tts_voice, $ivr->tts_speed);
        if ($generated) $ivr->update(['welcome_audio_path' => $generated]);
    }

    /** Generates (or clears) the cached TTS audio for every 'directive' option. */
    public function synthesizeDirectives(OrganizationIvr $ivr): void
    {
        foreach ($ivr->options as $option) {
            if ($option->destination_type !== 'directive') continue;
            $generatedPath = 'ivr-directive/'.$ivr->organization->public_id.'/'.$ivr->public_id.'-'.$option->digit.'.wav';
            if (! $option->directive_text) {
                if ($option->directive_audio_path === $generatedPath) {
                    Storage::disk('public')->delete($generatedPath);
                    $option->update(['directive_audio_path' => null]);
                }
                continue;
            }
            $generated = $this->piper->generate($option->directive_text, $generatedPath, $ivr->tts_voice, $ivr->tts_speed);
            if ($generated) $option->update(['directive_audio_path' => $generated]);
        }
    }
}
