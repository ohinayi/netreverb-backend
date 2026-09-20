<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\VoicemailResource;
use App\Models\Organization;
use App\Models\Voicemail;
use App\Services\Authorization\CallLogVisibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VoicemailController extends Controller
{
    public function __construct(private readonly CallLogVisibility $visibility) {}

    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Voicemail::class, $organization]);

        $user = $request->user();
        $canViewAll = Gate::allows('viewAll', [Voicemail::class, $organization]);

        $voicemails = $organization->voicemails()
            ->with(['extension.dialableNumber'])
            ->when(! $canViewAll, function ($query) use ($user, $organization): void {
                $query->whereIn('extension_id', $this->visibility->accessibleExtensionIds($user, $organization));
            })
            ->orderByDesc('created_at')
            ->paginate(20);

        return VoicemailResource::collection($voicemails);
    }

    public function show(Organization $organization, Voicemail $voicemail): BinaryFileResponse
    {
        abort_unless($voicemail->organization_id === $organization->id, 404);
        Gate::authorize('view', $voicemail);

        $disk = Storage::disk((string) config('telephony.voicemail.disk'));
        abort_unless($disk->exists($voicemail->file_path), 404);

        return response()->file($disk->path($voicemail->file_path), [
            'Content-Type' => 'audio/wav',
        ]);
    }

    public function markListened(Organization $organization, Voicemail $voicemail): VoicemailResource
    {
        abort_unless($voicemail->organization_id === $organization->id, 404);
        Gate::authorize('view', $voicemail);

        if ($voicemail->listened_at === null) {
            $voicemail->update(['listened_at' => now()]);
        }

        return new VoicemailResource($voicemail->load('extension.dialableNumber'));
    }

    public function destroy(Organization $organization, Voicemail $voicemail): Response
    {
        abort_unless($voicemail->organization_id === $organization->id, 404);
        Gate::authorize('delete', $voicemail);

        $disk = Storage::disk((string) config('telephony.voicemail.disk'));
        if ($disk->exists($voicemail->file_path)) {
            $disk->delete($voicemail->file_path);
        }
        $voicemail->delete();

        return response()->noContent();
    }
}
