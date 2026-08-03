<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ImportLeadsRequest;
use App\Http\Requests\Api\V1\ListLeadsRequest;
use App\Http\Requests\Api\V1\StoreLeadActivityRequest;
use App\Http\Requests\Api\V1\UpsertLeadRequest;
use App\Http\Resources\Api\V1\LeadActivityResource;
use App\Http\Resources\Api\V1\LeadResource;
use App\Models\CallLog;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Services\Auditing\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadController extends Controller
{
    private const IMPORTABLE_COLUMNS = [
        'name', 'company', 'email', 'phone', 'status', 'value', 'notes', 'assigned_user_email', 'follow_up_at', 'last_contacted_at',
    ];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(ListLeadsRequest $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('view', $organization);
        $filters = $request->validated();

        return $this->collectionFor($this->searchQuery($organization->leads()->getQuery(), $filters['search'] ?? null), $filters);
    }

    public function all(ListLeadsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = Lead::query()->whereHas(
            'organization',
            fn (Builder $organizationQuery) => $organizationQuery->visibleTo($request->user()),
        );

        return $this->collectionFor($this->searchQuery($query, $filters['search'] ?? null), $filters, includeOrganization: true);
    }

    /** @param array<string, mixed> $filters */
    private function collectionFor(Builder $baseQuery, array $filters, bool $includeOrganization = false): AnonymousResourceCollection
    {
        $counts = array_fill_keys(['new', 'contacted', 'qualified', 'won', 'lost'], 0);
        foreach ((clone $baseQuery)->selectRaw('status, COUNT(*) as total')->groupBy('status')->get() as $row) {
            $counts[$row->status] = (int) $row->total;
        }
        $activeFollowUps = (clone $baseQuery)->whereNotIn('status', ['won', 'lost'])->whereNotNull('follow_up_at');
        $followUps = [
            'overdue' => (clone $activeFollowUps)->where('follow_up_at', '<', now())->count(),
            'upcoming' => (clone $activeFollowUps)->where('follow_up_at', '>=', now())->count(),
        ];

        $leads = (clone $baseQuery)
            ->with('assignedUser:id,public_id,name,email')
            ->when($includeOrganization, fn (Builder $query) => $query->with('organization:id,public_id,name,slug'))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['follow_up'] ?? null, function (Builder $query, string $followUp): void {
                $query->whereNotIn('status', ['won', 'lost'])
                    ->whereNotNull('follow_up_at')
                    ->when(
                        $followUp === 'overdue',
                        fn (Builder $dueQuery) => $dueQuery->where('follow_up_at', '<', now()),
                        fn (Builder $dueQuery) => $dueQuery->where('follow_up_at', '>=', now()),
                    );
            })
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return LeadResource::collection($leads)->additional(['summary' => [
            'total' => array_sum($counts),
            'by_status' => $counts,
            'follow_ups' => $followUps,
        ]]);
    }

    public function store(UpsertLeadRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manageLeads', $organization);
        $attributes = $this->attributes($organization, $request->validated());
        $lead = $organization->leads()->create([...$attributes, 'created_by_user_id' => $request->user()->id]);
        $this->recordActivity($lead, $request->user()->id, 'created', 'Lead created.');
        $this->auditLogger->record($request, $request->user(), $organization, 'lead.created', $lead);

        return LeadResource::make($lead->load('assignedUser:id,public_id,name,email'))
            ->response()->setStatusCode(201);
    }

    public function export(Request $request, Organization $organization): StreamedResponse
    {
        Gate::authorize('view', $organization);

        return response()->streamDownload(function () use ($organization): void {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, self::IMPORTABLE_COLUMNS);

            $organization->leads()
                ->with('assignedUser:id,email')
                ->orderBy('id')
                ->each(function (Lead $lead) use ($stream): void {
                    fputcsv($stream, [
                        $this->csvValue($lead->name),
                        $this->csvValue($lead->company),
                        $this->csvValue($lead->email),
                        $this->csvValue($lead->phone),
                        $lead->status,
                        $lead->value,
                        $this->csvValue($lead->notes),
                        $lead->assignedUser?->email,
                        $lead->follow_up_at?->toIso8601String(),
                        $lead->last_contacted_at?->toIso8601String(),
                    ]);
                });
            fclose($stream);
        }, 'netreverb_leads_'.$organization->public_id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(ImportLeadsRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manageLeads', $organization);
        $rows = $this->csvRows($request->file('file'));
        $imported = 0;
        $errors = [];

        DB::transaction(function () use ($rows, $organization, $request, &$imported, &$errors): void {
            foreach ($rows as $index => $row) {
                try {
                    $payload = $this->importPayload($organization, $row);
                    $organization->leads()->create([...$payload, 'created_by_user_id' => $request->user()->id]);
                    $imported++;
                } catch (\Throwable $exception) {
                    $errors[] = ['row' => $index + 2, 'message' => $exception->getMessage()];
                }
            }
        });

        $this->auditLogger->record($request, $request->user(), $organization, 'leads.imported', null, null, [
            'imported' => $imported,
            'failed' => count($errors),
        ]);

        return response()->json(['imported' => $imported, 'failed' => count($errors), 'errors' => array_slice($errors, 0, 20)]);
    }

    public function update(UpsertLeadRequest $request, Organization $organization, Lead $lead): LeadResource
    {
        Gate::authorize('manageLeads', $organization);
        abort_unless($lead->organization_id === $organization->id, 404);
        $before = $lead->only(['name', 'company', 'email', 'phone', 'status', 'value', 'notes', 'assigned_user_id', 'last_contacted_at', 'follow_up_at']);
        $attributes = $this->attributes($organization, $request->validated());
        $followUpChanged = array_key_exists('follow_up_at', $attributes)
            && $lead->follow_up_at?->toIso8601String() !== $request->date('follow_up_at')?->toIso8601String();
        $assigneeChanged = array_key_exists('assigned_user_id', $attributes)
            && $lead->assigned_user_id !== $attributes['assigned_user_id'];

        if ($followUpChanged || $assigneeChanged) {
            $attributes['follow_up_notified_at'] = null;
            $attributes['follow_up_completed_at'] = null;
        }
        $lead->update($attributes);
        $changes = array_keys($lead->getChanges());
        $fresh = $lead->fresh()->load('assignedUser:id,public_id,name,email');
        if ($changes !== []) {
            $this->recordActivity($fresh, $request->user()->id, 'updated', 'Lead details updated.', ['fields' => $changes]);
        }
        $this->auditLogger->record($request, $request->user(), $organization, 'lead.updated', $fresh, $before, $fresh->only(['name', 'company', 'email', 'phone', 'status', 'value', 'notes', 'assigned_user_id', 'last_contacted_at', 'follow_up_at']));

        return LeadResource::make($fresh);
    }

    public function destroy(Request $request, Organization $organization, Lead $lead): JsonResponse
    {
        Gate::authorize('manageLeads', $organization);
        abort_unless($lead->organization_id === $organization->id, 404);
        $this->auditLogger->record($request, $request->user(), $organization, 'lead.deleted', $lead);
        $lead->delete();

        return response()->json(status: 204);
    }

    public function activities(Request $request, Organization $organization, Lead $lead): AnonymousResourceCollection
    {
        Gate::authorize('view', $organization);
        abort_unless($lead->organization_id === $organization->id, 404);

        return LeadActivityResource::collection(
            $lead->activities()->with(['actor:id,public_id,name', 'callLog'])->paginate(50),
        );
    }

    public function storeActivity(StoreLeadActivityRequest $request, Organization $organization, Lead $lead): JsonResponse
    {
        Gate::authorize('manageLeads', $organization);
        abort_unless($lead->organization_id === $organization->id, 404);
        $callLog = null;
        if ($request->filled('call_log_public_id')) {
            $callLog = CallLog::query()
                ->whereBelongsTo($organization)
                ->where('public_id', $request->string('call_log_public_id'))
                ->firstOrFail();
            Gate::authorize('view', $callLog);
        }

        $activity = $this->recordActivity(
            $lead,
            $request->user()->id,
            $request->validated('type'),
            $request->validated('summary'),
            $request->validated('metadata'),
            $callLog?->id,
        );
        if ($callLog !== null) {
            $lead->update(['last_contacted_at' => $callLog->ended_at ?? $callLog->started_at ?? $callLog->created_at]);
        }
        $this->auditLogger->record($request, $request->user(), $organization, 'lead.activity.created', $lead, null, [
            'activity_type' => $activity->type,
        ]);

        return LeadActivityResource::make($activity->load(['actor:id,public_id,name', 'callLog']))
            ->response()->setStatusCode(201);
    }

    /** @param array<string, mixed>|null $metadata */
    private function recordActivity(Lead $lead, ?int $actorUserId, string $type, string $summary, ?array $metadata = null, ?int $callLogId = null): LeadActivity
    {
        return $lead->activities()->create([
            'organization_id' => $lead->organization_id,
            'actor_user_id' => $actorUserId,
            'call_log_id' => $callLogId,
            'type' => $type,
            'summary' => $summary,
            'metadata' => $metadata,
        ]);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function attributes(Organization $organization, array $attributes): array
    {
        if (array_key_exists('assigned_user_public_id', $attributes)) {
            $attributes['assigned_user_id'] = $this->assignedUserId($organization, $attributes['assigned_user_public_id']);
            unset($attributes['assigned_user_public_id']);
        }

        return Arr::only($attributes, ['name', 'company', 'email', 'phone', 'status', 'value', 'notes', 'assigned_user_id', 'last_contacted_at', 'follow_up_at']);
    }

    private function assignedUserId(Organization $organization, ?string $publicId): ?int
    {
        if (blank($publicId)) {
            return null;
        }

        $userId = OrganizationMembership::query()
            ->whereBelongsTo($organization)
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('user', fn ($query) => $query->where('public_id', $publicId))
            ->value('user_id');

        if ($userId === null) {
            throw ValidationException::withMessages([
                'assigned_user_public_id' => 'Choose an active member of this organization.',
            ]);
        }

        return $userId;
    }

    private function searchQuery(Builder $query, ?string $search): Builder
    {
        if (blank($search)) {
            return $query;
        }

        $term = '%'.addcslashes($search, '%_\\').'%';

        return $query->where(function (Builder $leadQuery) use ($term): void {
            $leadQuery->where('name', 'like', $term)
                ->orWhere('company', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('phone', 'like', $term);
        });
    }

    /** @return array<int, array<string, string|null>> */
    private function csvRows(?UploadedFile $file): array
    {
        if ($file === null || ! ($stream = fopen($file->getRealPath(), 'rb'))) {
            throw ValidationException::withMessages(['file' => 'The CSV file could not be read.']);
        }

        $headers = fgetcsv($stream);
        $headers = is_array($headers) ? array_map(fn ($header) => strtolower(trim((string) $header)), $headers) : [];
        if ($headers === [] || ! in_array('name', $headers, true)) {
            fclose($stream);
            throw ValidationException::withMessages(['file' => 'The CSV must contain a name column.']);
        }

        $rows = [];
        while (($values = fgetcsv($stream)) !== false) {
            if (count($rows) >= 1000) {
                fclose($stream);
                throw ValidationException::withMessages(['file' => 'A CSV import may contain at most 1,000 leads.']);
            }
            if ($values === [null] || $values === []) {
                continue;
            }
            $row = [];
            foreach ($headers as $column => $header) {
                if (in_array($header, self::IMPORTABLE_COLUMNS, true)) {
                    $row[$header] = isset($values[$column]) ? trim((string) $values[$column]) : null;
                }
            }
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    /** @param array<string, string|null> $row
     * @return array<string, mixed>
     */
    private function importPayload(Organization $organization, array $row): array
    {
        $name = trim((string) ($row['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            throw new \InvalidArgumentException('Name must be between 2 and 160 characters.');
        }
        $status = $row['status'] ?: 'new';
        if (! in_array($status, ['new', 'contacted', 'qualified', 'won', 'lost'], true)) {
            throw new \InvalidArgumentException('Status must be new, contacted, qualified, won, or lost.');
        }
        if (filled($row['email'] ?? null) && filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Email is invalid.');
        }
        if (filled($row['value'] ?? null) && (! is_numeric($row['value']) || (float) $row['value'] < 0)) {
            throw new \InvalidArgumentException('Value must be a non-negative number.');
        }

        return [
            'name' => $name,
            'company' => $this->nullable($row['company'] ?? null),
            'email' => $this->nullable($row['email'] ?? null),
            'phone' => $this->nullable($row['phone'] ?? null),
            'status' => $status,
            'value' => $this->nullable($row['value'] ?? null),
            'notes' => $this->nullable($row['notes'] ?? null),
            'assigned_user_id' => $this->assignedUserIdByEmail($organization, $row['assigned_user_email'] ?? null),
            'follow_up_at' => $this->nullable($row['follow_up_at'] ?? null),
            'last_contacted_at' => $this->nullable($row['last_contacted_at'] ?? null),
        ];
    }

    private function assignedUserIdByEmail(Organization $organization, ?string $email): ?int
    {
        if (blank($email)) {
            return null;
        }

        $userId = OrganizationMembership::query()
            ->whereBelongsTo($organization)
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('user', fn ($query) => $query->where('email', $email))
            ->value('user_id');

        if ($userId === null) {
            throw new \InvalidArgumentException('Assigned user email is not an active organization member.');
        }

        return $userId;
    }

    private function nullable(?string $value): ?string
    {
        return blank($value) ? null : $value;
    }

    private function csvValue(?string $value): ?string
    {
        if ($value !== null && preg_match('/^[=+\-@]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
