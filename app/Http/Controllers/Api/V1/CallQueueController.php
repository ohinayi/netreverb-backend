<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpsertCallQueueRequest;
use App\Http\Resources\Api\V1\CallQueueResource;
use App\Jobs\SynchronizeFreeSwitchQueue;
use App\Models\CallQueue;
use App\Models\Department;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Auditing\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CallQueueController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        $membership = $this->queueMembership($request->user(), $organization);
        abort_unless($membership !== null, 403);

        return CallQueueResource::collection(
            $organization->callQueues()
                ->when($membership->role === MembershipRole::Supervisor, fn ($query) => $query->where('department_id', $membership->department_id))
                ->with($this->relations())->latest()->paginate(25),
        );
    }

    public function store(UpsertCallQueueRequest $request, Organization $organization): JsonResponse
    {
        $this->authorizeQueueManagement($request->user(), $organization);
        $attributes = $request->validated();
        $extension = $this->queueExtension($organization, $attributes['extension_public_id']);

        if (CallQueue::query()->where('extension_id', $extension->id)->exists()) {
            throw ValidationException::withMessages([
                'extension_public_id' => 'This extension already has a queue configuration.',
            ]);
        }

        $queue = DB::transaction(function () use ($organization, $extension, $attributes): CallQueue {
            $queue = $organization->callQueues()->create([
                'extension_id' => $extension->id,
                'department_id' => $this->departmentId($organization, $attributes['department_public_id'] ?? null),
                ...$this->settings($organization, $extension, $attributes),
            ]);
            $this->syncMembers($queue, $organization, $extension, $attributes['members']);

            return $queue;
        });
        SynchronizeFreeSwitchQueue::dispatch($queue->public_id)->afterCommit();
        $this->auditLogger->record($request, $request->user(), $organization, 'queue.created', $queue);

        return CallQueueResource::make($queue->load($this->relations()))
            ->response()->setStatusCode(201);
    }

    public function update(
        UpsertCallQueueRequest $request,
        Organization $organization,
        CallQueue $callQueue,
    ): CallQueueResource {
        $this->authorizeQueueManagement($request->user(), $organization, $callQueue);
        $attributes = $request->validated();
        $extension = $this->queueExtension($organization, $attributes['extension_public_id']);

        if ($extension->id !== $callQueue->extension_id) {
            throw ValidationException::withMessages([
                'extension_public_id' => 'A queue number cannot be changed after it is created.',
            ]);
        }

        DB::transaction(function () use ($callQueue, $organization, $extension, $attributes, $request): void {
            $settings = $this->settings($organization, $extension, $attributes);
            if ($request->user()->isSuperAdmin() || $this->queueMembership($request->user(), $organization)?->role !== MembershipRole::Supervisor) {
                $settings['department_id'] = $this->departmentId($organization, $attributes['department_public_id'] ?? null) ?? $callQueue->department_id;
            }
            $callQueue->update($settings);
            $this->syncMembers($callQueue, $organization, $extension, $attributes['members']);
        });
        SynchronizeFreeSwitchQueue::dispatch($callQueue->public_id)->afterCommit();
        $this->auditLogger->record($request, $request->user(), $organization, 'queue.updated', $callQueue);

        return CallQueueResource::make($callQueue->fresh()->load($this->relations()));
    }

    public function destroy(Request $request, Organization $organization, CallQueue $callQueue): JsonResponse
    {
        $this->authorizeQueueManagement($request->user(), $organization, $callQueue);
        $queueName = 'nr_'.$callQueue->load('extension.dialableNumber')->extension->dialableNumber->number.'@default';
        $callQueue->delete();
        SynchronizeFreeSwitchQueue::dispatch($queueName, true)->afterCommit();
        $this->auditLogger->record($request, $request->user(), $organization, 'queue.deleted', $callQueue);

        return response()->json(status: 204);
    }

    private function settings(Organization $organization, Extension $queueExtension, array $attributes): array
    {
        $fallbackId = null;
        if (($attributes['empty_queue_action'] ?? null) === 'forward_to_extension') {
            $fallback = $this->organizationExtension($organization, $attributes['fallback_extension_id'] ?? null);
            if ($fallback === null || $fallback->id === $queueExtension->id) {
                throw ValidationException::withMessages([
                    'fallback_extension_id' => 'Choose another active extension in this organization.',
                ]);
            }
            $fallbackId = $fallback->id;
        }

        return [
            'strategy' => $attributes['strategy'],
            'agent_ring_timeout_seconds' => $attributes['agent_ring_timeout_seconds'],
            'max_wait_seconds' => $attributes['max_wait_seconds'],
            'empty_queue_action' => $attributes['empty_queue_action'],
            'fallback_extension_id' => $fallbackId,
            'enabled' => $attributes['enabled'] ?? true,
        ];
    }

    private function syncMembers(CallQueue $queue, Organization $organization, Extension $queueExtension, array $members): void
    {
        $ids = collect($members)->pluck('extension_public_id')->all();
        $extensions = Extension::query()
            ->whereBelongsTo($organization)
            ->whereIn('public_id', $ids)
            ->where('status', ExtensionStatus::Active)
            ->whereIn('type', [ExtensionType::User, ExtensionType::Room, ExtensionType::Device])
            ->get()
            ->keyBy('public_id');

        if ($extensions->count() !== count($ids) || $extensions->contains('id', $queueExtension->id)) {
            throw ValidationException::withMessages([
                'members' => 'Every queue member must be an active user, room, or device extension in this organization.',
            ]);
        }

        $queue->members()->delete();
        $queue->members()->createMany(collect($members)->map(fn (array $member): array => [
            'extension_id' => $extensions[$member['extension_public_id']]->id,
            'priority' => $member['priority'],
            'enabled' => $member['enabled'] ?? true,
        ])->all());
    }

    private function queueExtension(Organization $organization, string $publicId): Extension
    {
        $extension = $this->organizationExtension($organization, $publicId);
        if ($extension === null || $extension->type !== ExtensionType::Queue) {
            throw ValidationException::withMessages([
                'extension_public_id' => 'Choose an active extension with the Queue type.',
            ]);
        }

        return $extension;
    }

    private function organizationExtension(Organization $organization, ?string $publicId): ?Extension
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return Extension::query()
            ->whereBelongsTo($organization)
            ->where('public_id', $publicId)
            ->where('status', ExtensionStatus::Active)
            ->first();
    }

    private function relations(): array
    {
        return ['department', 'extension.dialableNumber', 'fallbackExtension', 'members.extension.dialableNumber'];
    }

    private function departmentId(Organization $organization, ?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return Department::query()->whereBelongsTo($organization)->where('public_id', $publicId)->valueOrFail('id');
    }

    private function authorizeQueueManagement(User $user, Organization $organization, ?CallQueue $queue = null): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }
        $membership = $this->queueMembership($user, $organization);
        if (in_array($membership?->role, [MembershipRole::Owner, MembershipRole::Admin, MembershipRole::TelephonyAdmin], true)) {
            return;
        }
        abort_unless($membership?->role === MembershipRole::Supervisor && $membership->department_id !== null && $queue?->department_id === $membership->department_id, 403);
    }

    private function queueMembership(User $user, Organization $organization): ?OrganizationMembership
    {
        return OrganizationMembership::query()->whereBelongsTo($organization)->whereBelongsTo($user)->where('status', MembershipStatus::Active->value)->first();
    }
}
