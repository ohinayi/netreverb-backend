<?php

namespace App\Services\Ai;

use App\Models\AiAssistant;
use App\Services\Telephony\PiperTtsService;
use Illuminate\Support\Facades\Storage;

/**
 * Pre-generates an assistant's welcome message, closing message, and each
 * field's question once, when the assistant is saved, the same way
 * IvrPromptSynthesizer does for IVR prompts - a live call only ever plays a
 * cached file, never waits on Piper mid-call. Only the confirmation phrase
 * ("Your name is Abdul, right?") has to be synthesized live, since it
 * depends on what the caller actually said.
 */
class AiAssistantPromptSynthesizer
{
    // Used when an assistant hasn't picked a voice of its own.
    public const DEFAULT_VOICE = 'en_US-lessac-medium';

    public const DEFAULT_SPEED = 1.0;

    public function __construct(private readonly PiperTtsService $piper) {}

    public function synthesizeAll(AiAssistant $assistant): void
    {
        $voice = $assistant->tts_voice ?: self::DEFAULT_VOICE;

        $this->synthesizeMessage($assistant, $voice, 'welcome_message', 'welcome_audio_path', 'welcome');
        $this->synthesizeMessage($assistant, $voice, 'closing_message', 'closing_audio_path', 'closing');

        foreach ($assistant->fields as $field) {
            $text = trim((string) $field->question);
            $generatedPath = 'ai-assistant-questions/'.$assistant->organization->public_id.'/'.$assistant->public_id.'-'.$field->key.'.wav';
            if ($text === '') {
                if ($field->question_audio_path === $generatedPath) {
                    Storage::disk('public')->delete($generatedPath);
                    $field->update(['question_audio_path' => null]);
                }
                continue;
            }
            $generated = $this->piper->generate($text, $generatedPath, $voice, self::DEFAULT_SPEED);
            if ($generated) $field->update(['question_audio_path' => $generated]);
        }
    }

    private function synthesizeMessage(AiAssistant $assistant, string $voice, string $textColumn, string $audioColumn, string $slug): void
    {
        $text = trim((string) $assistant->$textColumn);
        $generatedPath = 'ai-assistant-'.$slug.'/'.$assistant->organization->public_id.'/'.$assistant->public_id.'.wav';
        if ($text === '') {
            if ($assistant->$audioColumn === $generatedPath) {
                Storage::disk('public')->delete($generatedPath);
                $assistant->update([$audioColumn => null]);
            }
            return;
        }
        $generated = $this->piper->generate($text, $generatedPath, $voice, self::DEFAULT_SPEED);
        if ($generated) $assistant->update([$audioColumn => $generated]);
    }
}
