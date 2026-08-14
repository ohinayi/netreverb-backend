<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SynthesizeIvrPrompts;
use App\Models\Organization;
use App\Models\OrganizationIvr;
use App\Services\Telephony\PiperTtsService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationIvrController extends Controller
{
    private const MAX_NESTING_DEPTH = 5;

    public function index(Organization $organization)
    {
        $this->authorizeOrganization($organization);
        $ivrs = $organization->ivrs()->with('options')->latest()->get();
        $serviceNumberByIvrId = $this->serviceNumberByIvrPublicId($organization);
        $ivrs->each(fn (OrganizationIvr $ivr) => $ivr->setAttribute('service_number_id', $serviceNumberByIvrId->get($ivr->public_id)));

        return response()->json(['data' => $ivrs]);
    }

    /**
     * A service number's inbound routing points at an IVR via
     * configuration->ivr_public_id - there's no column on organization_ivrs
     * pointing the other way, so this is the only way to tell the builder
     * UI which number (if any) is already attached to a given menu.
     *
     * @return Collection<string, string> ivr public_id => service number public_id
     */
    private function serviceNumberByIvrPublicId(Organization $organization): Collection
    {
        return $organization->serviceNumbers()->get(['public_id', 'configuration'])
            ->filter(fn ($service) => ! empty($service->configuration['ivr_public_id'] ?? null))
            ->mapWithKeys(fn ($service) => [$service->configuration['ivr_public_id'] => $service->public_id]);
    }

    public function store(Request $request, Organization $organization)
    {
        $this->authorizeOrganization($organization, true);
        $data = $this->validated($request, $organization);
        if ($request->hasFile('welcome_audio')) {
            $data['welcome_audio_path'] = $request->file('welcome_audio')->store('ivr-welcome/'.$organization->public_id, 'public');
        }
        $options = $this->normalizeOptions($request->input('options', []), $organization, 0);
        $ivr = $this->persistTree($organization, $data, $options, null);
        $this->attachServiceNumber($organization, $ivr, $data);

        return response()->json(['data' => $ivr->load('options')], 201);
    }

    public function update(Request $request, Organization $organization, OrganizationIvr $ivr)
    {
        $this->authorizeOrganization($organization, true);
        abort_unless((int) $ivr->organization_id === (int) $organization->getKey(), 404);
        $data = $this->validated($request, $organization);
        if ($request->hasFile('welcome_audio')) {
            if ($ivr->welcome_audio_path) {
                Storage::disk('public')->delete($ivr->welcome_audio_path);
            }
            $data['welcome_audio_path'] = $request->file('welcome_audio')->store('ivr-welcome/'.$organization->public_id, 'public');
        }
        if ($request->has('options')) {
            $options = $this->normalizeOptions($request->input('options', []), $organization, 0, $ivr->public_id);
            $this->persistTree($organization, $data, $options, $ivr);
        } else {
            $ivr->update(collect($data)->except('service_number_id')->all());
        }
        $this->attachServiceNumber($organization, $ivr, $data);

        return response()->json(['data' => $ivr->fresh('options')]);
    }

    /**
     * Points a service number's inbound routing at this IVR - the only way
     * a menu becomes directly dialable rather than reachable purely as a
     * nested submenu. Runs on both create and edit, since a menu that
     * started as submenu-only (no service number picked at creation) can
     * later be given one from the edit form.
     */
    private function attachServiceNumber(Organization $organization, OrganizationIvr $ivr, array $data): void
    {
        if (empty($data['service_number_id'])) {
            return;
        }
        $service = $organization->serviceNumbers()->where('public_id', $data['service_number_id'])->firstOrFail();
        $configuration = $service->configuration ?? [];
        $configuration['ivr_public_id'] = $ivr->public_id;
        $service->update(['configuration' => $configuration]);
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
        $ivr->setAttribute('service_number_id', $this->serviceNumberByIvrPublicId($organization)->get($ivr->public_id));

        return response()->json(['data' => $ivr->load('options')]);
    }

    private function validated(Request $request, Organization $organization): array
    {
        $data = $request->validate([
            'service_number_id' => ['sometimes', 'nullable', 'string', Rule::exists('service_numbers', 'public_id')->where(fn ($query) => $query->where('organization_id', $organization->getKey()))],
            'name' => ['required', 'string', 'max:120'], 'welcome_text' => ['nullable', 'string', 'max:1000'],
            'welcome_audio_path' => ['nullable', 'string', 'max:500'], 'max_retries' => ['nullable', 'integer', 'min:0', 'max:5'],
            'tts_voice' => ['nullable', 'string', Rule::in(array_keys(config('tts.piper.voices', [])))],
            'tts_speed' => ['nullable', 'numeric', 'min:0.5', 'max:2.0'],
            'welcome_audio' => ['nullable', 'file', 'mimes:wav,mp3,ogg,m4a', 'max:10240'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:30'], 'enabled' => ['sometimes', 'boolean'],
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

    /**
     * Recursively validates and normalizes an options payload. A 'submenu'
     * option may either reference an existing standalone IVR (via
     * 'destination', the original flow) or carry an inline-built nested
     * menu (via a 'submenu' object with its own 'options') that the
     * builder UI lets an admin construct in the same form instead of
     * creating a separate IVR first. Laravel's array validation rules
     * can't express this unbounded recursive shape, so it's walked by
     * hand instead.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOptions(mixed $rawOptions, Organization $organization, int $depth, ?string $ownIvrPublicId = null): array
    {
        if (! is_array($rawOptions)) {
            $rawOptions = [];
        }
        if (count($rawOptions) > 10) {
            throw ValidationException::withMessages(['options' => 'A menu can have at most 10 options.']);
        }
        if ($depth >= self::MAX_NESTING_DEPTH) {
            throw ValidationException::withMessages(['options' => 'Menus can only be nested '.self::MAX_NESTING_DEPTH.' levels deep.']);
        }

        $seenDigits = [];
        $normalized = [];
        foreach (array_values($rawOptions) as $index => $raw) {
            $path = "options.{$index}";
            $raw = is_array($raw) ? $raw : [];

            $digit = (string) ($raw['digit'] ?? '');
            if (! preg_match('/^[0-9*#]$/', $digit)) {
                throw ValidationException::withMessages(["{$path}.digit" => 'Enter a single digit (0-9, * or #).']);
            }
            if (isset($seenDigits[$digit])) {
                throw ValidationException::withMessages(["{$path}.digit" => 'Each key press can only be used once in this menu.']);
            }
            $seenDigits[$digit] = true;

            $label = trim((string) ($raw['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 120) {
                throw ValidationException::withMessages(["{$path}.label" => 'Enter a label up to 120 characters.']);
            }

            $type = (string) ($raw['destination_type'] ?? '');
            if (! in_array($type, ['extension', 'queue', 'conference', 'external', 'announcement', 'hangup', 'submenu', 'directive'], true)) {
                throw ValidationException::withMessages(["{$path}.destination_type" => 'Select a valid option type.']);
            }

            $option = [
                'digit' => $digit, 'label' => $label, 'destination_type' => $type,
                'destination' => isset($raw['destination']) && $raw['destination'] !== '' ? (string) $raw['destination'] : null,
                'directive_text' => isset($raw['directive_text']) ? (string) $raw['directive_text'] : null,
                'enabled' => array_key_exists('enabled', $raw) ? (bool) $raw['enabled'] : true,
                'sort_order' => $index,
            ];

            if ($type === 'directive' && trim((string) $option['directive_text']) === '') {
                throw ValidationException::withMessages(["{$path}.directive_text" => 'Enter the message to play for this directive.']);
            }

            if ($type === 'submenu') {
                $nested = $raw['submenu'] ?? null;
                if (is_array($nested)) {
                    $nestedPublicId = isset($nested['public_id']) && $nested['public_id'] !== '' ? (string) $nested['public_id'] : null;
                    // A submenu can't nest itself directly - the runtime
                    // dialplan avoids eager recursion so this can't crash a
                    // call, but it would still be a confusing dead end for
                    // whoever built it, so reject it up front.
                    if ($ownIvrPublicId && $nestedPublicId === $ownIvrPublicId) {
                        throw ValidationException::withMessages(["{$path}.submenu" => 'A menu cannot nest itself.']);
                    }
                    $welcomeText = isset($nested['welcome_text']) ? (string) $nested['welcome_text'] : null;
                    if ($welcomeText !== null && mb_strlen($welcomeText) > 1000) {
                        throw ValidationException::withMessages(["{$path}.submenu.welcome_text" => 'Keep the welcome text under 1000 characters.']);
                    }
                    $childOptions = $this->normalizeOptions($nested['options'] ?? [], $organization, $depth + 1, $nestedPublicId);
                    $option['submenu'] = [
                        'public_id' => $nestedPublicId,
                        'name' => trim((string) ($nested['name'] ?? '')) !== '' ? trim((string) $nested['name']) : ($label.' menu'),
                        'welcome_text' => $welcomeText,
                        'tts_voice' => $nested['tts_voice'] ?? null,
                        'tts_speed' => isset($nested['tts_speed']) ? max(0.5, min(2.0, (float) $nested['tts_speed'])) : null,
                        'timeout_seconds' => isset($nested['timeout_seconds']) ? max(1, min(30, (int) $nested['timeout_seconds'])) : 5,
                        'max_retries' => isset($nested['max_retries']) ? max(0, min(5, (int) $nested['max_retries'])) : 2,
                        'enabled' => array_key_exists('enabled', $nested) ? (bool) $nested['enabled'] : true,
                        'options' => $childOptions,
                    ];
                } elseif (! empty($option['destination'])) {
                    $exists = $organization->ivrs()->where('public_id', $option['destination'])->exists();
                    if (! $exists) {
                        throw ValidationException::withMessages(["{$path}.destination" => 'Select an existing IVR menu in this organization to nest as a submenu.']);
                    }
                } else {
                    throw ValidationException::withMessages(["{$path}.destination" => 'Build a nested submenu or select an existing IVR menu.']);
                }
            }

            $normalized[] = $option;
        }

        return $normalized;
    }

    /**
     * Recursively creates or updates an IVR and its options. A submenu
     * option carrying an inline-built 'submenu' definition has its child
     * IVR persisted first (matched to an existing row by public_id when
     * editing, created otherwise) so the parent option can point its
     * 'destination' at the child's public_id, exactly like a submenu that
     * references a pre-existing standalone IVR.
     *
     * @param  array<int, array<string, mixed>>  $options
     */
    private function persistTree(Organization $organization, array $attrs, array $options, ?OrganizationIvr $existing): OrganizationIvr
    {
        $ivrAttrs = collect($attrs)->only(['name', 'welcome_text', 'welcome_audio_path', 'tts_voice', 'tts_speed', 'max_retries', 'timeout_seconds', 'enabled'])->all();
        $ivr = $existing ? tap($existing)->update($ivrAttrs) : $organization->ivrs()->create($ivrAttrs);
        // A freshly created row may have picked up column defaults (e.g.
        // tts_voice) that this in-memory instance never had attributes
        // set for - refresh so a nested submenu inheriting "the parent's
        // voice" below sees the real value instead of null.
        $ivr->refresh();

        foreach ($options as &$option) {
            if ($option['destination_type'] === 'submenu' && isset($option['submenu'])) {
                $childAttrs = $option['submenu'];
                $childExisting = ! empty($childAttrs['public_id'])
                    ? $organization->ivrs()->where('public_id', $childAttrs['public_id'])->first()
                    : null;
                // A nested menu built inline without its own voice/speed
                // picks up the parent's, so an admin building a whole tree
                // in one sitting only has to choose it once.
                $childAttrs['tts_voice'] ??= $ivr->tts_voice;
                $childAttrs['tts_speed'] ??= $ivr->tts_speed;
                $child = $this->persistTree($organization, $childAttrs, $childAttrs['options'], $childExisting);
                $option['destination'] = $child->public_id;
            }
            unset($option['submenu']);
        }
        unset($option);

        $ivr->options()->delete();
        $ivr->options()->createMany($options);
        // Piper synthesis is a subprocess call per prompt; a tree with a
        // nested submenu and a few directives can chain enough of them to
        // blow past the frontend's request timeout if run inline here.
        // The dialplan already falls back to live flite speech for
        // anything not yet cached, so queuing this is free.
        SynthesizeIvrPrompts::dispatch($ivr->id);

        return $ivr;
    }

    private function authorizeOrganization(Organization $organization, bool $manage = false): void
    {
        abort_if($organization->isPersonalWorkspace(), 404);
        $membership = request()->user()?->organizations()->whereKey($organization->getKey())->first();
        abort_unless($membership, 403);
        if ($manage) {
            abort_unless(in_array($membership->pivot->role ?? null, ['owner', 'admin'], true), 403);
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
        $relativePath = 'ivr-preview/'.$organization->public_id.'/'.(string) Str::uuid().'.wav';
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
