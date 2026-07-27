<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpsertCallQueueRequest;
use App\Http\Resources\Api\V1\CallQueueResource;
use App\Jobs\SynchronizeFreeSwitchQueue;
use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CallQueueController extends Controller
{
    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('create', [Extension::class, $organization]);

        return CallQueueResource::collection(
            $organization->callQueues()->with($this->relations())->latest()->paginate(25),
        );
    }

    public function store(UpsertCallQueueRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('create', [Extension::class, $organization]);
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
                ...$this->settings($organization, $extension, $attributes),
            ]);
            $this->syncMembers($queue, $organization, $extension, $attributes['members']);

            return $queue;
        });
        SynchronizeFreeSwitchQueue::dispatch($queue->public_id)->afterCommit();

        return CallQueueResource::make($queue->load($this->relations()))
            ->response()->setStatusCode(201);
    }

    public function update(
        UpsertCallQueueRequest $request,
        Organization $organization,
        CallQueue $callQueue,
    ): CallQueueResource {
        Gate::authorize('create', [Extension::class, $organization]);
        $attributes = $request->validated();
        $extension = $this->queueExtension($organization, $attributes['extension_public_id']);

        if ($extension->id !== $callQueue->extension_id) {
            throw ValidationException::withMessages([
                'extension_public_id' => 'A queue number cannot be changed after it is created.',
            ]);
        }

        DB::transaction(function () use ($callQueue, $organization, $extension, $attributes): void {
            $callQueue->update($this->settings($organization, $extension, $attributes));
            $this->syncMembers($callQueue, $organization, $extension, $attributes['members']);
        });
        SynchronizeFreeSwitchQueue::dispatch($callQueue->public_id)->afterCommit();

        return CallQueueResource::make($callQueue->fresh()->load($this->relations()));
    }

    public function destroy(Organization $organization, CallQueue $callQueue): JsonResponse
    {
        Gate::authorize('create', [Extension::class, $organization]);
        $queueName = 'nr_'.$callQueue->load('extension.dialableNumber')->extension->dialableNumber->number.'@default';
        $callQueue->delete();
        SynchronizeFreeSwitchQueue::dispatch($queueName, true)->afterCommit();

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
        if ($publicId === null || $publicId === '') return null;

        return Extension::query()
            ->whereBelongsTo($organization)
            ->where('public_id', $publicId)
            ->where('status', ExtensionStatus::Active)
            ->first();
    }

    private function relations(): array
    {
        return ['extension.dialableNumber', 'fallbackExtension', 'members.extension.dialableNumber'];
    }
}
