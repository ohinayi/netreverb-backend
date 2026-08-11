<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CallNoteResource;
use App\Models\CallLog;
use App\Models\CallNote;
use App\Models\Organization;
use App\Services\Authorization\CallLogVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CallLogNoteController extends Controller
{
    public function __construct(private readonly CallLogVisibility $callLogVisibility) {}

    public function index(Organization $organization, CallLog $callLog): AnonymousResourceCollection
    {
        abort_unless($callLog->organization_id === $organization->id, Response::HTTP_NOT_FOUND);
        Gate::authorize('view', $callLog);

        return CallNoteResource::collection(
            $callLog->notes()->with('user')->get()
        );
    }

    public function store(Request $request, Organization $organization, CallLog $callLog): JsonResponse
    {
        abort_unless($callLog->organization_id === $organization->id, Response::HTTP_NOT_FOUND);
        Gate::authorize('view', $callLog);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $note = $callLog->notes()->create([
            'organization_id' => $organization->id,
            'user_id' => $request->user()->getKey(),
            'body' => $data['body'],
        ]);

        return CallNoteResource::make($note->load('user'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(Request $request, Organization $organization, CallLog $callLog, CallNote $note): Response
    {
        abort_unless($callLog->organization_id === $organization->id, Response::HTTP_NOT_FOUND);
        abort_unless($note->call_log_id === $callLog->id, Response::HTTP_NOT_FOUND);

        $isAuthor = $note->user_id !== null && $note->user_id === $request->user()->getKey();

        if (! $isAuthor) {
            Gate::authorize('update', $callLog);
        }

        $note->delete();

        return response()->noContent();
    }

    /**
     * Independent notes feed for the organization's Notes tab. Unlike the
     * call log listing, this is never suppressed by call-log deduplication
     * since it queries notes directly.
     */
    public function indexForOrganization(Request $request, Organization $organization): AnonymousResourceCollection
    {
        $user = $request->user();
        $canViewAll = $this->callLogVisibility->canViewAll($user, $organization);
        $accessibleExtensionIds = $canViewAll
            ? []
            : $this->callLogVisibility->accessibleExtensionIds($user, $organization);

        $notes = CallNote::query()
            ->where('organization_id', $organization->id)
            ->with(['user', 'callLog'])
            ->when(! $canViewAll, function ($query) use ($accessibleExtensionIds): void {
                $query->whereHas('callLog', function ($callLogQuery) use ($accessibleExtensionIds): void {
                    $callLogQuery->whereIn('caller_extension_id', $accessibleExtensionIds)
                        ->orWhereIn('callee_extension_id', $accessibleExtensionIds);
                });
            })
            ->latest()
            ->paginate(20);

        return CallNoteResource::collection($notes);
    }
}
