<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RingbackAdStatus;
use App\Http\Controllers\Controller;
use App\Models\RingbackAd;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SuperAdminRingbackAdsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $ads = RingbackAd::query()
            ->with('organization:id,public_id,name')
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->get();

        return response()->json(['data' => $ads]);
    }

    /**
     * A super-admin-authored clip - not tied to any organization, and
     * auto-approved since there's no one else to review it.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'audio' => ['required', 'file', 'mimes:wav,mp3,ogg,m4a', 'max:10240'],
        ]);

        $ad = RingbackAd::query()->create([
            'organization_id' => null,
            'title' => $data['title'],
            'audio_path' => $request->file('audio')->store('ringback-ads/super-admin', 'public'),
            'status' => RingbackAdStatus::Approved,
            'enabled' => true,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json(['data' => $ad], 201);
    }

    public function update(Request $request, RingbackAd $ringbackAd): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(RingbackAdStatus::class)],
            'enabled' => ['sometimes', 'boolean'],
            'rejection_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('status', $data)) {
            $data['reviewed_by_user_id'] = $request->user()->id;
            $data['reviewed_at'] = now();
        }

        $ringbackAd->update($data);

        return response()->json(['data' => $ringbackAd->fresh()]);
    }

    public function destroy(Request $request, RingbackAd $ringbackAd): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        Storage::disk('public')->delete($ringbackAd->audio_path);
        $ringbackAd->delete();

        return response()->json(status: 204);
    }
}
