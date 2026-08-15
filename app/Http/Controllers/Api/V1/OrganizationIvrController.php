<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationIvr;
use App\Services\Telephony\PiperTtsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationIvrController extends Controller
{
    public function index(Organization $organization)
    {
        $this->authorizeOrganization($organization);
        return response()->json(['data' => $organization->ivrs()->with('options')->latest()->get()]);
    }

    public function store(Request $request, Organization $organization)
    {
        $this->authorizeOrganization($organization, true);
        $data = $this->validated($request, $organization, true);
        if ($request->hasFile('welcome_audio')) {
            $data['welcome_audio_path'] = $request->file('welcome_audio')->store('ivr-welcome/'.$organization->public_id, 'public');
        }
        // service_number_id selects the inbound route; it is stored on the
        // service configuration below, not as a column on organization_ivrs.
        $ivr = $organization->ivrs()->create(collect($data)->except(['options', 'service_number_id'])->all());
        $ivr->options()->createMany($data['options'] ?? []);
        $this->synthesizePrompt($ivr, $data['options'] ?? []);
        $this->synthesizeDirectives($ivr);
        // A submenu that only ever gets reached by nesting under another
        // IVR's option has no service number of its own to dial into.
        if (! empty($data['service_number_id'])) {
            $service = $organization->serviceNumbers()->where('public_id', $data['service_number_id'])->firstOrFail();
            $configuration = $service->configuration ?? [];
            $configuration['ivr_public_id'] = $ivr->public_id;
            $service->update(['configuration' => $configuration]);
        }
        return response()->json(['data' => $ivr->load('options')], 201);
    }

    public function update(Request $request, Organization $organization, OrganizationIvr $ivr)
    {
        $this->authorizeOrganization($organization, true);
        abort_unless((int) $ivr->organization_id === (int) $organization->getKey(), 404);
        $data = $this->validated($request, $organization, false);
        if ($request->hasFile('welcome_audio')) {
            if ($ivr->welcome_audio_path) Storage::disk('public')->delete($ivr->welcome_audio_path);
            $data['welcome_audio_path'] = $request->file('welcome_audio')->store('ivr-welcome/'.$organization->public_id, 'public');
        }
        $ivr->update(collect($data)->except(['options', 'service_number_id'])->all());
        if (array_key_exists('options', $data)) {
            $ivr->options()->delete();
            $ivr->options()->createMany($data['options'] ?? []);
        }
        $this->synthesizePrompt($ivr, $data['options'] ?? $ivr->options()->get()->toArray());
        $this->synthesizeDirectives($ivr);
        return response()->json(['data' => $ivr->load('options')]);
    }

    public function destroy(Organization $organization, OrganizationIvr $ivr)
    {
        $this->authorizeOrganization($organization, true);
        abort_unless((int) $ivr->organization_id === (int) $organization->getKey(), 404);
        $ivr->delete();
        return response()->noContent();
    }

    public function show(Organization $organization, OrganizationIvr $ivr)
    {
        $this->authorizeOrganization($organization);
        abort_unless((int) $ivr->organization_id === (int) $organization->getKey(), 404);
        return response()->json(['data' => $ivr->load('options')]);
    }

    private function validated(Request $request, Organization $organization, bool $creating): array
    {
        $data = $request->validate([
            'service_number_id' => ['sometimes', 'nullable', 'string', Rule::exists('service_numbers', 'public_id')->where(fn ($query) => $query->where('organization_id', $organization->getKey()))],
            'name' => ['required', 'string', 'max:120'], 'welcome_text' => ['nullable', 'string', 'max:1000'],
            'welcome_audio_path' => ['nullable', 'string', 'max:500'], 'max_retries' => ['nullable', 'integer', 'min:0', 'max:5'],
            'tts_voice' => ['nullable', 'string', Rule::in(array_keys(config('tts.piper.voices', [])))],
            'tts_speed' => ['nullable', 'numeric', 'min:0.5', 'max:2.0'],
            'welcome_audio' => ['nullable', 'file', 'mimes:wav,mp3,ogg,m4a', 'max:10240'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:30'], 'enabled' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'array', 'max:10'], 'options.*.digit' => ['required', 'regex:/^[0-9*#]$/'],
            'options.*.label' => ['required', 'string', 'max:120'],
            'options.*.destination_type' => ['required', Rule::in(['extension', 'queue', 'conference', 'external', 'announcement', 'hangup', 'submenu', 'directive'])],
            'options.*.destination' => ['nullable', 'string', 'max:255'], 'options.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:99'],
            'options.*.directive_text' => ['nullable', 'string', 'max:2000'],
            'options.*.enabled' => ['sometimes', 'boolean'],
        ]);
        if (! empty($data['service_number_id'])) {
            $service = $organization->serviceNumbers()->where('public_id', $data['service_number_id'])->first();
            if (! $service || ! $service->enabled) {
                throw ValidationException::withMessages(['service_number_id' => 'Select an active service number assigned to this organization.']);
            }
        }
        foreach ($data['options'] ?? [] as $index => $option) {
            if ($option['destination_type'] === 'submenu') {
                $exists = $organization->ivrs()->where('public_id', $option['destination'] ?? null)->exists();
                if (! $exists) {
                    throw ValidationException::withMessages(["options.{$index}.destination" => 'Select an existing IVR menu in this organization to nest as a submenu.']);
                }
            }
            if ($option['destination_type'] === 'directive' && ! trim($option['directive_text'] ?? '')) {
                throw ValidationException::withMessages(["options.{$index}.directive_text" => 'Enter the message to play for this directive.']);
            }
        }
        return $data;
    }

    private function authorizeOrganization(Organization $organization, bool $manage = false): void
    {
        abort_if($organization->isPersonalWorkspace(), 404);
        $membership = request()->user()?->organizations()->whereKey($organization->getKey())->first();
        abort_unless($membership, 403);
        if ($manage) abort_unless(in_array($membership->pivot->role ?? null, ['owner', 'admin'], true), 403);
    }

    private function synthesizePrompt(OrganizationIvr $ivr, array $options): void
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
        $menu = collect($options)->where('enabled', true)->map(
            fn (array $option): string => 'Press '.$option['digit'].' for '.$option['label'].'.'
        )->implode(' ');
        $text = trim($ivr->welcome_text.'. '.$menu);
        $generated = app(PiperTtsService::class)->generate($text, $generatedPath, $ivr->tts_voice, $ivr->tts_speed);
        if ($generated) $ivr->update(['welcome_audio_path' => $generated]);
    }

    /**
     * Pre-generates the TTS audio for every 'directive' option, the same
     * way synthesizePrompt() does for the welcome prompt: once at save time
     * so a live call only ever plays a cached file.
     */
    private function synthesizeDirectives(OrganizationIvr $ivr): void
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
            $generated = app(PiperTtsService::class)->generate($option->directive_text, $generatedPath, $ivr->tts_voice, $ivr->tts_speed);
            if ($generated) $option->update(['directive_audio_path' => $generated]);
        }
    }

    public function preview(Request $request, Organization $organization)
    {
        $this->authorizeOrganization($organization, true);
        $data = $request->validate([
            'welcome_text' => ['required', 'string', 'max:1000'],
            'tts_voice' => ['nullable', 'string', Rule::in(array_keys(config('tts.piper.voices', [])))],
            'tts_speed' => ['nullable', 'numeric', 'min:0.5', 'max:2.0'],
        ]);
        // Previews live in their own throwaway path so they never collide
        // with, or get mistaken for, an IVR's saved generated prompt.
        $relativePath = 'ivr-preview/'.$organization->public_id.'/'.(string) \Illuminate\Support\Str::uuid().'.wav';
        $generated = app(PiperTtsService::class)->generate(
            $data['welcome_text'],
            $relativePath,
            $data['tts_voice'] ?? null,
            isset($data['tts_speed']) ? (float) $data['tts_speed'] : null,
        );
        abort_unless($generated, 422, 'Preview audio could not be generated. Check the Piper voice configuration.');
        return response()->json(['data' => ['url' => Storage::disk('public')->url($generated)]]);
    }
}
