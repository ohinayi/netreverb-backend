<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpsertAiAssistantRequest;
use App\Http\Resources\Api\V1\AiAssistantResource;
use App\Http\Resources\Api\V1\AiAssistantSessionResource;
use App\Jobs\SynthesizeAiAssistantQuestions;
use App\Models\AiAssistant;
use App\Models\AiAssistantSession;
use App\Models\Extension;
use App\Models\Organization;
use App\Services\Auditing\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AiAssistantController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('view', $organization);

        return AiAssistantResource::collection(
            $organization->aiAssistants()->with($this->relations())->latest()->get(),
        );
    }

    public function store(UpsertAiAssistantRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('update', $organization);
        $attributes = $request->validated();

        $assistant = DB::transaction(function () use ($organization, $attributes): AiAssistant {
            $assistant = $organization->aiAssistants()->create($this->assistantAttributes($organization, $attributes));
            $this->replaceFields($assistant, $attributes['fields'] ?? []);

            return $assistant;
        });
        SynthesizeAiAssistantQuestions::dispatch($assistant->id);
        $this->auditLogger->record($request, $request->user(), $organization, 'ai_assistant.created', $assistant);

        return AiAssistantResource::make($assistant->load($this->relations()))
            ->response()->setStatusCode(201);
    }

    public function update(
        UpsertAiAssistantRequest $request,
        Organization $organization,
        AiAssistant $aiAssistant,
    ): AiAssistantResource {
        Gate::authorize('update', $organization);
        abort_unless($aiAssistant->organization_id === $organization->id, 404);
        $attributes = $request->validated();
        $before = $aiAssistant->only(['name', 'extension_id', 'enabled', 'language', 'tts_voice', 'welcome_message', 'closing_message', 'system_instruction', 'knowledge', 'handoff_rules']);

        DB::transaction(function () use ($aiAssistant, $organization, $attributes): void {
            $aiAssistant->update($this->assistantAttributes($organization, $attributes));
            if (array_key_exists('fields', $attributes)) {
                $this->replaceFields($aiAssistant, $attributes['fields']);
            }
        });
        SynthesizeAiAssistantQuestions::dispatch($aiAssistant->id);
        $fresh = $aiAssistant->fresh()->load($this->relations());
        $this->auditLogger->record($request, $request->user(), $organization, 'ai_assistant.updated', $fresh, $before, $fresh->only(['name', 'extension_id', 'enabled', 'language', 'tts_voice', 'welcome_message', 'closing_message', 'system_instruction', 'knowledge', 'handoff_rules']));

        return AiAssistantResource::make($fresh);
    }

    public function destroy(Request $request, Organization $organization, AiAssistant $aiAssistant): JsonResponse
    {
        Gate::authorize('update', $organization);
        abort_unless($aiAssistant->organization_id === $organization->id, 404);

        $aiAssistant->delete();
        $this->auditLogger->record($request, $request->user(), $organization, 'ai_assistant.deleted', $aiAssistant);

        return response()->json(status: 204);
    }

    public function sessions(Organization $organization, AiAssistant $aiAssistant): AnonymousResourceCollection
    {
        Gate::authorize('view', $organization);
        abort_unless($aiAssistant->organization_id === $organization->id, 404);

        return AiAssistantSessionResource::collection(
            $aiAssistant->sessions()->with('callLog:id,public_id,caller_number')->latest('id')->paginate(30),
        );
    }

    /** Every session across every assistant in the org, newest first - the call log for assisted calls. */
    public function allSessions(Request $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('view', $organization);

        $query = AiAssistantSession::query()
            ->where('organization_id', $organization->id)
            ->with(['assistant:id,public_id,name', 'callLog:id,public_id,caller_number']);

        if ($request->filled('assistant_id')) {
            $query->whereHas('assistant', fn ($assistantQuery) => $assistantQuery->where('public_id', $request->string('assistant_id')));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return AiAssistantSessionResource::collection($query->latest('id')->paginate(30)->withQueryString());
    }

    /** @param array<string, mixed> $attributes */
    private function assistantAttributes(Organization $organization, array $attributes): array
    {
        $extensionId = null;
        if (array_key_exists('extension_public_id', $attributes) && filled($attributes['extension_public_id'])) {
            $extensionId = Extension::query()
                ->whereBelongsTo($organization)
                ->where('public_id', $attributes['extension_public_id'])
                ->value('id');

            if ($extensionId === null) {
                throw ValidationException::withMessages([
                    'extension_public_id' => 'Choose an extension that belongs to this organization.',
                ]);
            }
        }

        return [
            ...Arr::only($attributes, ['name', 'enabled', 'language', 'tts_voice', 'welcome_message', 'closing_message', 'system_instruction', 'knowledge', 'handoff_rules']),
            'extension_id' => $extensionId,
        ];
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function replaceFields(AiAssistant $assistant, array $fields): void
    {
        $assistant->fields()->delete();
        $assistant->fields()->createMany(array_map(
            fn (array $field, int $index): array => [
                ...Arr::only($field, ['key', 'label', 'field_type', 'question', 'required', 'options']),
                'sort_order' => $field['sort_order'] ?? $index,
            ],
            $fields,
            array_keys($fields),
        ));
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return ['extension', 'fields'];
    }
}
