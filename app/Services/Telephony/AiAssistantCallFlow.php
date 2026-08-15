<?php

namespace App\Services\Telephony;

use App\Contracts\Ai\AudioTranscriptionProvider;
use App\Contracts\Ai\StructuredAssistantProvider;
use App\Models\AiAssistant;
use App\Models\AiAssistantField;
use App\Models\AiAssistantSession;
use App\Models\AiUsageRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Live-call, turn-by-turn version of the AI assistant, entered by dialing a
 * service number configured with type=assistant. Unlike
 * ProcessAiAssistantCallRecording (which transcribes a whole finished
 * recording after the call ends), this asks one field at a time: play the
 * question, record the answer with FreeSWITCH's own silence detection,
 * transcribe just that clip, extract just that field, read the value back
 * for a DTMF confirm/redo, then move on. Each step is served by a fresh
 * XML-cURL re-fetch, the same mechanism the IVR's digit-press and submenu
 * flows already use (see FreeSwitchDialplanController) - this class is
 * composed into that controller rather than duplicating its dialplan
 * plumbing.
 */
class AiAssistantCallFlow
{
    public const ANSWER_CONTEXT_PREFIX = 'ai-assistant-answer-';

    public const CONFIRM_CONTEXT_PREFIX = 'ai-assistant-confirm-';

    public function __construct(
        private readonly PiperTtsService $piper,
        private readonly AudioTranscriptionProvider $transcription,
        private readonly StructuredAssistantProvider $structuredAssistant,
    ) {}

    /** Starts a session and asks the first field, called from the normal inbound-call XML-cURL request. */
    public function emitEntry(\DOMDocument $xml, \DOMElement $condition, AiAssistant $assistant): void
    {
        $session = AiAssistantSession::query()->create([
            'organization_id' => $assistant->organization_id,
            'ai_assistant_id' => $assistant->id,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        $firstField = $assistant->fields->first();
        if (! $firstField) {
            $this->appendPlaybackOrSpeak($xml, $condition, $assistant->welcome_audio_path, $assistant->welcome_message ?: 'This assistant has nothing configured yet.');
            $session->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
            $this->appendHangup($xml, $condition);

            return;
        }

        if ($assistant->welcome_message || $assistant->welcome_audio_path) {
            $this->appendSleep($xml, $condition);
            $this->appendPlaybackOrSpeak($xml, $condition, $assistant->welcome_audio_path, $assistant->welcome_message);
        }

        $session->update(['current_field_key' => $firstField->key]);
        $this->emitQuestionAndRecord($xml, $condition, $session, $firstField);
    }

    /**
     * Serves the re-fetch after a `record` completes: transcribes the
     * caller's answer, extracts the field's value, and asks for a
     * confirm/redo digit.
     */
    public function handleAnswer(Request $request, string $contextName)
    {
        $publicId = substr($contextName, strlen(self::ANSWER_CONTEXT_PREFIX));
        [$xml, $context, $condition] = $this->skeleton($contextName);
        $session = AiAssistantSession::query()->with('assistant.fields')->where('public_id', $publicId)->first();

        if (! $session || $session->status !== 'in_progress') {
            $this->appendHangup($xml, $condition);

            return $this->respond($xml);
        }

        $field = $session->assistant->fields->firstWhere('key', $session->current_field_key);
        if (! $field) {
            $this->finishSessionAndSayGoodbye($xml, $condition, $session);

            return $this->respond($xml);
        }

        $transcript = $this->transcribeAnswer($session, $field);
        $session->update(['transcript' => trim(($session->transcript ?? '')."\n".$field->key.': '.$transcript)]);

        if (trim($transcript) === '') {
            $this->emitRedoOrSkip($xml, $condition, $session, $field, "Sorry, I didn't catch that.");

            return $this->respond($xml);
        }

        $value = $this->extractFieldValue($session, $field, $transcript);

        if ($value === null || $value === '') {
            $this->emitRedoOrSkip($xml, $condition, $session, $field, "Sorry, I didn't catch that.");

            return $this->respond($xml);
        }

        $session->update(['pending_value' => (string) $value]);
        $this->appendSpeak($xml, $condition, "You said, for {$field->label}: {$value}. Press 1 to confirm, or 2 to try again.");
        $this->appendReadDigit($xml, $condition, 'ai_confirm_digit', 8);
        $this->appendTransfer($xml, $condition, '${ai_confirm_digit}', self::CONFIRM_CONTEXT_PREFIX.$session->public_id);

        return $this->respond($xml);
    }

    /** Serves the re-fetch after the confirm/redo digit is read. */
    public function handleConfirm(Request $request, string $contextName)
    {
        $digit = (string) ($request->input('destination_number') ?: $request->input('Caller-Destination-Number'));
        $publicId = substr($contextName, strlen(self::CONFIRM_CONTEXT_PREFIX));
        [$xml, $context, $condition] = $this->skeleton($contextName);
        $session = AiAssistantSession::query()->with('assistant.fields')->where('public_id', $publicId)->first();

        if (! $session || $session->status !== 'in_progress') {
            $this->appendHangup($xml, $condition);

            return $this->respond($xml);
        }

        $field = $session->assistant->fields->firstWhere('key', $session->current_field_key);
        if (! $field) {
            $this->finishSessionAndSayGoodbye($xml, $condition, $session);

            return $this->respond($xml);
        }

        if ($digit === '1') {
            $captured = $session->captured_data ?? [];
            $captured[$field->key] = $session->pending_value;
            $session->update(['captured_data' => $captured, 'pending_value' => null, 'retry_count' => 0]);
            $this->advanceToNextFieldOrFinish($xml, $condition, $session);

            return $this->respond($xml);
        }

        $this->emitRedoOrSkip($xml, $condition, $session, $field, "Let's try that again.");

        return $this->respond($xml);
    }

    private function emitQuestionAndRecord(\DOMDocument $xml, \DOMElement $condition, AiAssistantSession $session, AiAssistantField $field): void
    {
        $this->appendSleep($xml, $condition);
        $this->appendPlaybackOrSpeak($xml, $condition, $field->question_audio_path, $field->question);

        $recordPath = $this->recordingPath($session, $field);
        $absolutePath = rtrim((string) config('telephony.ai_assistant.base_path'), '/').'/'.$recordPath;
        $action = $condition->appendChild($xml->createElement('action'));
        $action->setAttribute('application', 'record');
        // record syntax: path, time_limit_secs, silence_thresh, silence_hits.
        // FreeSWITCH stops on its own once the caller has been quiet for
        // silence_hits, rather than always waiting out time_limit_secs.
        $action->setAttribute('data', sprintf(
            '%s %d %d %d',
            $absolutePath,
            (int) config('telephony.ai_assistant.record_max_seconds', 20),
            (int) config('telephony.ai_assistant.record_silence_threshold', 200),
            (int) config('telephony.ai_assistant.record_silence_hits', 3),
        ));

        $this->appendTransfer($xml, $condition, 'continue', self::ANSWER_CONTEXT_PREFIX.$session->public_id);
    }

    private function emitRedoOrSkip(\DOMDocument $xml, \DOMElement $condition, AiAssistantSession $session, AiAssistantField $field, string $apology): void
    {
        $maxRetries = (int) config('telephony.ai_assistant.max_retries', 2);
        $retryCount = $session->retry_count + 1;

        if ($retryCount > $maxRetries) {
            $captured = $session->captured_data ?? [];
            $captured[$field->key] = null;
            $session->update(['captured_data' => $captured, 'pending_value' => null, 'retry_count' => 0]);
            $this->advanceToNextFieldOrFinish($xml, $condition, $session);

            return;
        }

        $session->update(['retry_count' => $retryCount, 'pending_value' => null]);
        $this->appendSpeak($xml, $condition, $apology);
        $this->emitQuestionAndRecord($xml, $condition, $session->fresh(), $field);
    }

    private function advanceToNextFieldOrFinish(\DOMDocument $xml, \DOMElement $condition, AiAssistantSession $session): void
    {
        $fields = $session->assistant->fields;
        $currentIndex = $fields->search(fn (AiAssistantField $candidate): bool => $candidate->key === $session->current_field_key);
        $next = $currentIndex !== false ? $fields->slice($currentIndex + 1)->first() : $fields->first();

        if ($next) {
            $session->update(['current_field_key' => $next->key]);
            $this->emitQuestionAndRecord($xml, $condition, $session->fresh(), $next);

            return;
        }

        $this->finishSessionAndSayGoodbye($xml, $condition, $session);
    }

    private function finishSessionAndSayGoodbye(\DOMDocument $xml, \DOMElement $condition, AiAssistantSession $session): void
    {
        $assistant = $session->assistant;
        $session->forceFill([
            'status' => 'completed',
            'current_field_key' => null,
            'pending_value' => null,
            'completed_at' => now(),
        ])->save();
        $this->appendPlaybackOrSpeak($xml, $condition, $assistant->closing_audio_path, $assistant->closing_message ?: 'Thank you. Goodbye.');
        $this->appendHangup($xml, $condition);
    }

    private function transcribeAnswer(AiAssistantSession $session, AiAssistantField $field): string
    {
        $disk = (string) config('telephony.ai_assistant.disk');
        $path = $this->recordingPath($session, $field);

        try {
            $this->fetchRemoteRecordingIfNeeded($disk, $path);

            if (! Storage::disk($disk)->exists($path) || Storage::disk($disk)->size($path) < 100) {
                return '';
            }

            $transcript = $this->transcription->transcribe($disk, $path);
            AiUsageRecord::query()->create([
                'organization_id' => $session->organization_id,
                'ai_assistant_session_id' => $session->id,
                'provider' => (string) config('ai.transcription.provider'),
                'usage_type' => 'transcription',
                'metadata' => ['field' => $field->key, 'live' => true],
            ]);

            return $transcript;
        } catch (Throwable $exception) {
            Log::warning('AI assistant live transcription failed.', [
                'session_id' => $session->id, 'field' => $field->key, 'exception' => $exception->getMessage(),
            ]);

            return '';
        }
    }

    private function extractFieldValue(AiAssistantSession $session, AiAssistantField $field, string $transcript): ?string
    {
        try {
            $result = $this->structuredAssistant->extract(
                $this->instructionFor($session->assistant),
                $transcript,
                [
                    'type' => 'object',
                    'properties' => [
                        $field->key => [
                            'type' => $field->field_type === 'boolean' ? 'boolean' : 'string',
                            'nullable' => true,
                            'description' => $field->question ?: $field->label,
                        ],
                    ],
                ],
            );
            AiUsageRecord::query()->create([
                'organization_id' => $session->organization_id,
                'ai_assistant_session_id' => $session->id,
                'provider' => 'gemini',
                'usage_type' => 'structured_extraction',
                'input_units' => Str::wordCount($transcript),
                'metadata' => ['field' => $field->key, 'live' => true, 'model' => config('ai.gemini.model')],
            ]);

            $value = $result[$field->key] ?? null;

            return is_string($value) || is_numeric($value) || is_bool($value) ? (string) $value : null;
        } catch (Throwable $exception) {
            Log::warning('AI assistant live extraction failed.', [
                'session_id' => $session->id, 'field' => $field->key, 'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Folds the org's own business context (menu, tone, escalation rules -
     * whatever they wrote in "Assistant instructions") into the extraction
     * prompt, the same way the post-call batch processor
     * (ProcessAiAssistantCallRecording) already does. Without this, a
     * caller saying "the spicy one" could never be matched against an
     * actual menu item - the model would only ever see the bare field
     * description.
     */
    private function instructionFor(AiAssistant $assistant): string
    {
        return implode("\n\n", array_filter([
            $assistant->system_instruction,
            'Extract only the value for the field described below from the caller\'s spoken answer. Treat the answer as the only source of truth - never guess, invent a value, or correct noisy speech. Use null if the value was not clearly spoken.',
        ]));
    }

    private function recordingPath(AiAssistantSession $session, AiAssistantField $field): string
    {
        // Deliberately flat, no per-session subdirectory: FreeSWITCH's
        // `record` app can't create missing parent directories on its own,
        // and the SSH user this app fetches recordings through in local dev
        // only has read/traverse (not write) access to the recordings
        // folder - a subdirectory that needs creating at record time would
        // never actually get created, and record would fail outright.
        return 'answers-'.$session->public_id.'-'.$field->key.'-'.$session->retry_count.'.wav';
    }

    /**
     * Pulls the clip FreeSWITCH just recorded from the FreeSWITCH box over
     * SSH into this app's own storage, synchronously, when the two aren't
     * on the same machine (local dev). A no-op in production, where this
     * app and FreeSWITCH share a filesystem and telephony.ai_assistant.disk
     * already points straight at base_path.
     */
    private function fetchRemoteRecordingIfNeeded(string $disk, string $relativePath): void
    {
        if (! config('telephony.ai_assistant.remote_fetch.enabled')) {
            return;
        }

        $localPath = rtrim((string) config('filesystems.disks.'.$disk.'.root'), '/').'/'.$relativePath;
        $remotePath = rtrim((string) config('telephony.ai_assistant.base_path'), '/').'/'.$relativePath;
        $host = (string) config('telephony.ai_assistant.remote_fetch.host');
        $user = (string) config('telephony.ai_assistant.remote_fetch.user');
        $password = config('telephony.ai_assistant.remote_fetch.password');

        Storage::disk($disk)->makeDirectory(dirname($relativePath));

        $command = ['scp', '-o', 'StrictHostKeyChecking=accept-new', sprintf('%s@%s:%s', $user, $host, $remotePath), $localPath];
        $environment = [];
        $askPassScript = null;

        if (filled($password)) {
            $askPassScript = tempnam(sys_get_temp_dir(), 'netreverb-ai-ssh-askpass-');
            file_put_contents($askPassScript, "#!/bin/sh\nprintf '%s' \"\$NETREVERB_AI_SSH_PASSWORD\"\n");
            chmod($askPassScript, 0700);
            $environment = [
                'DISPLAY' => 'netreverb-ai-sync:0',
                'SSH_ASKPASS' => $askPassScript,
                'SSH_ASKPASS_REQUIRE' => 'force',
                'NETREVERB_AI_SSH_PASSWORD' => $password,
            ];
        }

        $process = new Process($command, base_path(), $environment);
        $process->setTimeout(10);

        try {
            // A failed fetch (recording not written yet, network hiccup)
            // isn't fatal here - the exists()/size() check right after this
            // call is what actually decides whether a usable answer was
            // captured, same as it would locally.
            $process->run();
        } catch (Throwable) {
        } finally {
            if ($askPassScript !== null && file_exists($askPassScript)) {
                @unlink($askPassScript);
            }
        }
    }

    private function appendSpeak(\DOMDocument $xml, \DOMElement $condition, string $text): void
    {
        if (trim($text) === '') return;
        $action = $condition->appendChild($xml->createElement('action'));
        $action->setAttribute('application', 'speak');
        $action->setAttribute('data', 'flite|slt|'.str_replace('|', ' ', $text));
    }

    /**
     * Same 1500ms the IVR flow uses before its own first word (see
     * FreeSwitchDialplanController::appendMenuActions) - proven necessary
     * to avoid clipping the first syllables on SIP/WebRTC endpoints that
     * are still opening their audio element right after answer/transfer.
     */
    private function appendSleep(\DOMDocument $xml, \DOMElement $condition): void
    {
        $action = $condition->appendChild($xml->createElement('action'));
        $action->setAttribute('application', 'sleep');
        $action->setAttribute('data', '1500');
    }

    /**
     * Plays a pre-generated Piper prompt if one exists (welcome/closing
     * messages and field questions are fixed per-assistant text, so they're
     * synthesized once in the assistant's chosen voice by
     * AiAssistantPromptSynthesizer, not live). Falls back to live flite
     * speech - always the same generic voice, since flite and Piper are
     * different engines - when there's no cached audio yet.
     */
    private function appendPlaybackOrSpeak(\DOMDocument $xml, \DOMElement $condition, ?string $audioPath, ?string $text): void
    {
        if ($audioPath) {
            $action = $condition->appendChild($xml->createElement('action'));
            $action->setAttribute('application', 'playback');
            $audioBaseUrl = (string) config('telephony.freeswitch.ivr_audio_base_url', '');
            $resolved = $audioBaseUrl !== ''
                ? $audioBaseUrl.'/storage/'.ltrim($audioPath, '/')
                : storage_path('app/public/'.$audioPath);
            $action->setAttribute('data', $resolved);

            return;
        }

        $this->appendSpeak($xml, $condition, (string) $text);
    }

    private function appendReadDigit(\DOMDocument $xml, \DOMElement $condition, string $variable, int $timeoutSeconds): void
    {
        $action = $condition->appendChild($xml->createElement('action'));
        $action->setAttribute('application', 'read');
        $action->setAttribute('data', '1 1 silence_stream://1000 '.$variable.' '.max(5000, $timeoutSeconds * 1000).' #');
    }

    private function appendTransfer(\DOMDocument $xml, \DOMElement $condition, string $destination, string $context): void
    {
        $action = $condition->appendChild($xml->createElement('action'));
        $action->setAttribute('application', 'transfer');
        $action->setAttribute('data', $destination.' XML '.$context);
    }

    private function appendHangup(\DOMDocument $xml, \DOMElement $condition): void
    {
        $action = $condition->appendChild($xml->createElement('action'));
        $action->setAttribute('application', 'hangup');
        $action->setAttribute('data', 'NORMAL_CLEARING');
    }

    /** @return array{0: \DOMDocument, 1: \DOMElement, 2: \DOMElement} document, context, and a catch-all condition to append actions into */
    private function skeleton(string $contextName): array
    {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $document = $xml->appendChild($xml->createElement('document'));
        $document->setAttribute('type', 'freeswitch/xml');
        $section = $document->appendChild($xml->createElement('section'));
        $section->setAttribute('name', 'dialplan');
        $context = $section->appendChild($xml->createElement('context'));
        $context->setAttribute('name', $contextName);

        // This context only ever serves one purpose (continue the flow for
        // this session), so a single catch-all extension is enough - unlike
        // the IVR's options context, there is no branching to route here.
        $extension = $context->appendChild($xml->createElement('extension'));
        $extension->setAttribute('name', 'continue');
        $condition = $extension->appendChild($xml->createElement('condition'));
        $condition->setAttribute('field', 'destination_number');
        $condition->setAttribute('expression', '^.*$');

        return [$xml, $context, $condition];
    }

    private function respond(\DOMDocument $xml)
    {
        return response($xml->saveXML(), 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }
}
