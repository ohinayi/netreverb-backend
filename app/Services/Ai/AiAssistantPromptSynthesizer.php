<?php

namespace App\Services\Ai;

use App\Models\AiAssistant;
use App\Services\Telephony\PiperTtsService;
use Illuminate\Support\Facades\Storage;

/**
 * Pre-generates an assistant's welcome message, closing message, and each
 * field's question (plus its "You said, for X:" confirm prefix) once, when
 * the assistant is saved, the same way IvrPromptSynthesizer does for IVR
 * prompts - a live call only ever plays a cached file, never waits on Piper
 * mid-call. Only the caller's actual captured value has to be spoken live
 * (flite), since it depends on what they said - everything else around it
 * is now Piper too, instead of the whole confirmation sentence being flite.
 */
class AiAssistantPromptSynthesizer
{
    // Used when an assistant hasn't picked a voice of its own.
    public const DEFAULT_VOICE = 'en_US-lessac-medium';

    public const DEFAULT_SPEED = 1.0;

    public const CONFIRM_PROMPT_TEXT = 'Press 1 to confirm, or 2 to try again.';

    public const REDO_PROMPT_TEXT = "Sorry, let's try that again.";

    public const DTMF_NUMBER_PROMPT_TEXT = 'Enter it now on your keypad, then press the pound key.';

    public const DTMF_BOOLEAN_PROMPT_TEXT = 'Press 1 for yes, or 2 for no.';

    public function __construct(private readonly PiperTtsService $piper) {}

    public function synthesizeAll(AiAssistant $assistant): void
    {
        $voice = $assistant->tts_voice ?: self::DEFAULT_VOICE;

        $this->synthesizeMessage($assistant, $voice, 'welcome_message', 'welcome_audio_path', 'welcome');
        $this->synthesizeMessage($assistant, $voice, 'closing_message', 'closing_audio_path', 'closing');
        $this->synthesizeShared($voice, self::CONFIRM_PROMPT_TEXT, self::sharedPromptPath($voice, 'confirm-prompt'));
        $this->synthesizeShared($voice, self::REDO_PROMPT_TEXT, self::sharedPromptPath($voice, 'redo-prompt'));
        $this->synthesizeShared($voice, self::DTMF_NUMBER_PROMPT_TEXT, self::sharedPromptPath($voice, 'dtmf-number-prompt'));
        $this->synthesizeShared($voice, self::DTMF_BOOLEAN_PROMPT_TEXT, self::sharedPromptPath($voice, 'dtmf-boolean-prompt'));

        foreach ($assistant->fields as $field) {
            $text = trim((string) $field->question);
            $generatedPath = 'ai-assistant-questions/'.$assistant->organization->public_id.'/'.$assistant->public_id.'-'.$field->key.'.wav';
            if ($text === '') {
                if ($field->question_audio_path === $generatedPath) {
                    Storage::disk('public')->delete($generatedPath);
                    $field->update(['question_audio_path' => null]);
                }
            } else {
                $generated = $this->piper->generate($text, $generatedPath, $voice, self::DEFAULT_SPEED);
                if ($generated) {
                    $field->update(['question_audio_path' => $generated]);
                }
            }

            $confirmPrefixPath = 'ai-assistant-questions/'.$assistant->organization->public_id.'/'.$assistant->public_id.'-'.$field->key.'-confirm-prefix.wav';
            $confirmPrefixText = 'You said, for '.$field->label.':';
            $generatedPrefix = $this->piper->generate($confirmPrefixText, $confirmPrefixPath, $voice, self::DEFAULT_SPEED);
            if ($generatedPrefix) {
                $field->update(['confirm_prefix_audio_path' => $generatedPrefix]);
            }
        }
    }

    /** Deterministic path for fixed text shared by every assistant using a given voice, so it's generated once total rather than once per assistant. */
    public static function sharedPromptPath(string $voice, string $slug): string
    {
        return 'ai-assistant-shared/'.$voice.'-'.$slug.'.wav';
    }

    private function synthesizeShared(string $voice, string $text, string $path): void
    {
        if (Storage::disk('public')->exists($path)) {
            return;
        }
        $this->piper->generate($text, $path, $voice, self::DEFAULT_SPEED);
    }

    private function synthesizeMessage(AiAssistant $assistant, string $voice, string $textColumn, string $audioColumn, string $slug): void
    {
        $text = trim((string) $assistant->$textColumn);
        $generatedPath = 'ai-assistant-'.$slug.'/'.$assistant->organization->public_id.'/'.$assistant->public_id.'.wav';
        if ($text === '') {
            if ($generatedPath === $assistant->$audioColumn) {
                Storage::disk('public')->delete($generatedPath);
                $assistant->update([$audioColumn => null]);
            }

            return;
        }
        $generated = $this->piper->generate($text, $generatedPath, $voice, self::DEFAULT_SPEED);
        if ($generated) {
            $assistant->update([$audioColumn => $generated]);
        }
    }
}
