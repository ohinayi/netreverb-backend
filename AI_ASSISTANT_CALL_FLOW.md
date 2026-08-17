# AI assistant live call flow

Handover guide for the live, turn-by-turn phone AI assistant: an assistant
configured with a list of fields (name, phone, address, ...) that calls a
caller, asks each field's question, listens or reads keypad digits, confirms,
and stores structured `captured_data` on the session. This is distinct from
the older post-call batch transcript matcher (see "Old vs new flow" below) -
read this doc for the live flow that actually answers calls today.

## Architecture

```
caller -> FreeSWITCH -> XML-cURL POST (context=ai-assistant-*) -> Laravel
                                                                      |
                                                    AiAssistantCallFlow builds
                                                    the NEXT bit of dialplan XML
                                                    and returns it synchronously
```

Same mechanism as the IVR flow (see `TELEPHONY_IVR_RUNBOOK.md`): every state
transition is a fresh XML-cURL request. FreeSWITCH's `transfer <dest> XML
<context>` action forces it to re-fetch dialplan XML from Laravel with that
context name. The three live contexts:

* `ai-assistant-answer-{session_public_id}` - caller just finished speaking,
  come get the recording and process it.
* `ai-assistant-confirm-{session_public_id}` - caller just pressed 1 (confirm)
  or 2 (redo) after hearing their answer read back.
* `ai-assistant-dtmf-{session_public_id}` - caller just typed digits for a
  phone/number/boolean field.

`FreeSwitchDialplanController::__invoke()` reads `context` (falling back to
`Caller-Context`), routes on these prefixes to `AiAssistantCallFlow`, and
falls through to normal extension/service-number routing otherwise. A call
only ever enters the flow via a `ServiceNumber` of `type = assistant` whose
`configuration.ai_assistant_id` is set - see "Routing a call in" below.

## Data model

* `AiAssistant` - one assistant: `name`, `system_instruction` (business
  context folded into every extraction prompt), `welcome_message`,
  `closing_message`, `tts_voice`, plus cached audio paths
  (`welcome_audio_path`, `closing_audio_path`).
* `AiAssistantField` - one question: `key`, `label`, `question`, `field_type`
  (`text`, `phone`, `number`, `boolean`), `question_audio_path`,
  `confirm_prefix_audio_path` (see Piper section).
* `AiAssistantSession` - one in-progress or finished call: `status`
  (`in_progress` / `completed` / `failed`), `current_field_key`,
  `pending_value`, `retry_count`, `captured_data` (final structured output),
  `transcript` (running log of every raw answer, mainly for debugging).
* `AiAssistant.extension_id` - **not used by this flow at all.** It only
  matters to the old batch flow (`ProcessAiAssistantCallRecording`, fired by
  `CallLogObserver` after a normal human-answered call finishes) that
  after-the-fact matches a completed recording to an assistant. Do not expect
  setting the Extension field to make live calls route anywhere.

## Routing a call in

1. Create a `ServiceNumber` with `type = assistant`.
2. Set `configuration.ai_assistant_id` to the assistant's public id (done via
   the "AI assistant" select on the Service Numbers page when
   `type = assistant`).
3. Calling that service number's number is what triggers `emitEntry()`.

If the referenced assistant (or, on the IVR path, the referenced IVR) is
missing/disabled, `FreeSwitchDialplanController` plays "This service is
temporarily unavailable. Please try again later." and hangs up, instead of
silently failing.

## Turn-by-turn flow (`AiAssistantCallFlow`)

1. **`emitEntry()`** - creates the `AiAssistantSession`, plays the welcome
   message (1500ms pre-roll sleep first - see "Timing" below), asks the
   first field via `emitQuestionForField()`.
2. **`emitQuestionForField()`** dispatches per `field_type`:
   * `boolean` / `phone` / `number` -> `emitQuestionAndCollectDtmf()`
   * everything else (`text`) -> `emitQuestionAndRecord()`
3a. **Speech path** (`emitQuestionAndRecord`) - plays the question, records
   the caller's answer (`record_max_seconds`, silence-detection config in
   `config/telephony.php`), transfers to `ai-assistant-answer-{id}`.
   `handleAnswer()` then:
   * fetches the recording (`fetchRemoteRecordingIfNeeded()` - see below),
   * transcribes it (Whisper via the injected `AudioTranscriptionProvider`),
   * extracts a structured value from the transcript (Gemini via
     `StructuredAssistantProvider`, schema built from the field + the
     assistant's own `system_instruction`),
   * on success, reads back the value and asks for confirm/redo
     (`ai-assistant-confirm-{id}`),
   * on empty transcript or failed extraction, calls `emitRedoOrSkip()`.
3b. **DTMF path** (`emitQuestionAndCollectDtmf`) - plays the question, plays
   a shared "press 1 for yes, 2 for no" / "enter it on your keypad, then
   press pound" prompt, does a FreeSWITCH `read` for keypad digits, transfers
   to `ai-assistant-dtmf-{id}`. `handleDtmfAnswer()` normalizes the digits
   (boolean: `1`->Yes, `2`->No, anything else invalid) and stores the value
   directly - **no Whisper, no Gemini, no confirm step**, since keypad entry
   isn't ambiguous.
4. **`handleConfirm()`** - digit `1` commits `pending_value` into
   `captured_data` and calls `advanceToNextFieldOrFinish()`; anything else
   calls `emitRedoOrSkip()`.
5. **`emitRedoOrSkip()`** - retries the same field (plays a shared "sorry,
   let's try that again" prompt then re-asks) up to `ai_assistant.max_retries`
   (default 2), then gives up and advances with a null value for that field.
6. **`advanceToNextFieldOrFinish()`** - moves `current_field_key` to the next
   field, or calls `finishSessionAndSayGoodbye()` if none remain.
7. **`finishSessionAndSayGoodbye()`** - marks the session `completed`, plays
   the closing message, hangs up.

## Why DTMF exists for phone/number/boolean

FreeSWITCH's own XML-cURL fetch has a hard **~10 second timeout** (confirmed
empirically from VPS logs - `Context ... not found` appears exactly 10s after
the `transfer ... to XML[...]` line). The speech path's full chain (record +
recording fetch + Whisper transcription + Gemini extraction) routinely blows
past that budget, especially once network latency is involved. Speech is also
inherently lossy for exact digits (phone numbers) and yes/no choices.

Phone, number, and boolean fields have a keypad-native equivalent, so they
skip the entire speech pipeline: faster (no Whisper/Gemini round trip at all)
and perfectly accurate (no STT ambiguity to correct). Free-text fields (name,
address, open-ended order text) have no keypad equivalent and must stay on
the speech path - their latency and accuracy ceiling is bounded by Whisper
model quality/speed and the call audio path, not by anything fixable in this
codebase alone.

**When adding a new field**, setting `field_type` to `phone`, `number`, or
`boolean` automatically opts it into the DTMF path - no other configuration
needed.

## Piper TTS pre-generation

Same pattern as `TELEPHONY_IVR_RUNBOOK.md`'s IVR prompts: fixed text is
synthesized once at save time (`AiAssistantPromptSynthesizer::synthesizeAll()`,
called on assistant save) into a deterministic cached WAV; the dialplan XML
just points `playback` at it. Live `flite` `speak` is only used as a fallback
(cache missing) and for the caller's own captured value being read back
during confirmation, since that's genuinely dynamic per-call content.

Two tiers of caching:

* **Per-assistant, per-field**: `question_audio_path` (the question itself)
  and `confirm_prefix_audio_path` ("You said, for {label}:") - depend on
  assistant-specific text, generated once per assistant/field/voice
  combination, path includes the assistant's public id.
* **Shared, per-voice**: text identical across every assistant using a given
  voice - "Press 1 to confirm, or 2 to try again.", "Sorry, let's try that
  again.", "Press 1 for yes, or 2 for no.", "Enter it now on your keypad,
  then press the pound key." Cached once per voice (not per assistant) at
  `ai-assistant-shared/{voice}-{slug}.wav` via
  `AiAssistantPromptSynthesizer::sharedPromptPath()`. Generation is
  idempotent (skips if the file already exists); at runtime,
  `AiAssistantCallFlow::sharedPromptIfExists()` just checks existence (not
  DB-tracked) and falls back to flite if the file isn't there yet.

If you add a new fixed prompt string, follow the shared-prompt pattern (add a
constant + a `synthesizeShared()` call in `synthesizeAll()`), not a new
per-assistant column - it avoids redundant generation across assistants.

**Voice**: `AiAssistant.tts_voice`, defaults to
`AiAssistantPromptSynthesizer::DEFAULT_VOICE` (`en_US-lessac-medium`) when
unset. Changing an assistant's voice does not regenerate old shared prompts
under the old voice's cache key - they just stop being referenced.

## Recording storage: local dev vs prod

FreeSWITCH always runs on the VPS. Recordings it writes live at
`config('telephony.ai_assistant.base_path')`
(`/usr/local/freeswitch/var/lib/freeswitch/recordings/ai-assistant`), owned by
the `freeswitch` OS user.

* **Local dev**: Laravel runs on your laptop, a different machine entirely -
  it cannot read that path directly.
* **Prod**: Laravel and FreeSWITCH share the VPS, but as *different OS
  users* (`www-data` vs `freeswitch`) with no shared write access - `www-data`
  still can't read those files directly.

Both cases are solved the same way: `AiAssistantCallFlow::
fetchRemoteRecordingIfNeeded()` does a synchronous `scp` (password auth via a
generated `SSH_ASKPASS` script, mirroring `CallRecordingVpsSynchronizer`'s
pattern for regular call recordings) from the VPS into the app's own
`ai_assistant_recordings` disk, gated by
`AI_ASSISTANT_RECORDINGS_REMOTE_FETCH_ENABLED`. In prod this loops back to
the same box over SSH - unnecessary-looking but works uniformly regardless of
topology, and prod's same-box loopback is fast. `scp` is capped at a 10s
process timeout and fails silently (no exception) if the recording isn't
ready yet or the fetch times out - the `exists()`/`size() < 100` check right
after is what actually decides whether a usable answer was captured.

Recording filenames are **flat**, not nested per-session
(`answers-{session_public_id}-{field_key}-{retry_count}.wav` directly under
the `ai-assistant` dir) - FreeSWITCH's `record` app does not create missing
parent directories, and neither can the `deploy` SSH user (read-only ACL on
that tree).

## Known local-dev limitation (not a bug)

Local dev has no local Whisper install - `WHISPER_CPP_URL` points through the
reverse SSH tunnel at the VPS's whisper.cpp server
(`127.0.0.1:18081 -> 127.0.0.1:8081`). Every transcription call for a
free-text field therefore travels over your home internet connection through
that tunnel, on top of actual inference time. Measured: ~69 seconds for a
single short answer locally vs the FreeSWITCH's ~10s XML-cURL budget - the
call always dies before that response can land. This is real, unavoidable
network latency, not something fixable in app code.

**Practical upshot**: test free-text-field-heavy flows (name, address,
open-ended text) on prod, not locally. DTMF fields (phone/number/boolean)
never touch Whisper at all and work fine locally.

## Session lifecycle / cleanup

A session only leaves `in_progress` via natural completion
(`finishSessionAndSayGoodbye`). If a call just drops mid-flow, nothing
marks it done - `App\Jobs\ExpireStaleAiAssistantSessions` (scheduled every 5
minutes, see `routes/console.php`) marks any `in_progress` session whose
`updated_at` is older than 10 minutes as `failed`, so the AI call log doesn't
fill up with phantom in-progress entries.

## Extraction prompt

`AiAssistantCallFlow::instructionFor()` folds the assistant's own
`system_instruction` into the Gemini extraction prompt, so free-text answers
can be matched against org-specific context (e.g. "the spicy one" against an
actual menu described in the instructions). `fieldDescription()` adds an
extra instruction for `phone` fields to preserve every spoken digit exactly,
rather than "correcting" toward a more familiar-looking number - this reduces
but cannot eliminate digit loss from a mis-transcription upstream (which is
exactly why `phone` fields are on the DTMF path now, not speech).

## Frontend

* `src/views/AiAssistantsView.vue` - assistant + field builder. Field rows
  are label-first with an auto-generated key (slugified from the label) -
  the key input only becomes independently editable once touched directly.
  A per-row hint marks which field types are DTMF-collected. Voice picker
  and closing-message textarea live here too. The Extension select has an
  explicit caption clarifying it does **not** affect live call routing.
* `src/views/AiAssistantSessionsView.vue` - org-wide "AI call log" page,
  filterable by assistant/status, backed by
  `GET /organizations/{org}/ai-assistant-sessions`
  (`AiAssistantController::allSessions()`). Use this over the old
  per-assistant modal to see everything across assistants at once.
* `src/views/ServiceNumbersView.vue` - when `type = assistant`, shows the
  "AI assistant" select that writes `configuration.ai_assistant_id`.

## Old vs new flow (don't confuse these)

| | Old (batch) | New (live, this doc) |
|---|---|---|
| Trigger | `CallLogObserver` after a normal human-answered call's recording finishes | `ServiceNumber.type = assistant` dialing directly into `emitEntry()` |
| Linked via | `AiAssistant.extension_id` | `ServiceNumber.configuration.ai_assistant_id` |
| Interaction | None - after-the-fact transcript matching | Real-time, turn-by-turn during the call |
| Entry point | `ProcessAiAssistantCallRecording` job | `AiAssistantCallFlow` |

## Testing locally

1. `composer dev` from `netreverb-backend` (keeps server, reverse tunnel,
   queue worker, and Piper prompt regeneration reachable).
2. Confirm `AI_ASSISTANT_RECORDINGS_REMOTE_FETCH_ENABLED=true` in `.env`.
3. Call a service number wired to an assistant from a local test extension
   (see `TELEPHONY_IVR_RUNBOOK.md` for the local-test-extension routing
   mechanism).
4. Expect DTMF fields (phone/number/boolean) to work at normal speed.
   Free-text fields will very likely time out locally - see the limitation
   above. This is expected; verify those on prod instead.
5. Check `AiAssistantSession` + `AiUsageRecord` rows via tinker if a call
   dies with no clear symptom - `retry_count`, `current_field_key`,
   `transcript`, and `AiUsageRecord.created_at` timestamps (transcription vs
   extraction) pinpoint exactly where time went.

## Known constraints (not further fixable via app code alone)

* FreeSWITCH's own ~10s XML-cURL timeout is a hard ceiling on any live
  request's total processing time.
* Whisper transcription quality/speed for free-text fields is bounded by the
  whisper.cpp model and the VPS's own CPU, not by this codebase.
* The captured-value portion of the confirmation readback is always live
  flite (it's dynamic content) - only the surrounding wrapper text is Piper.

## Possible next steps

* Streaming/incremental STT to shrink the free-text latency below the 10s
  ceiling, if free-text field accuracy/speed becomes a blocker at scale.
* A FAISS-backed retrieval layer was discussed and explicitly deferred - not
  needed until a single org's own knowledge base (menu, FAQ, policy docs)
  stops fitting in Gemini's context window. Don't build it speculatively.
* Consider surfacing the local-dev free-text latency limitation directly in
  the UI (a dev-only banner) if new developers keep hitting it unknowingly.
