<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RingbackAdStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\RingbackAd;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrganizationRingbackAdController extends Controller
{
    public function index(Organization $organization)
    {
        Gate::authorize('update', $organization);

        return response()->json([
            'data' => RingbackAd::query()->whereBelongsTo($organization)->latest()->get(),
        ]);
    }

    public function store(Request $request, Organization $organization)
    {
        Gate::authorize('update', $organization);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'audio' => ['required', 'file', 'mimes:wav,mp3,ogg,m4a', 'max:10240'],
        ]);

        $ad = RingbackAd::query()->create([
            'organization_id' => $organization->id,
            'title' => $data['title'],
            'audio_path' => $request->file('audio')->store('ringback-ads/'.$organization->public_id, 'public'),
            'status' => RingbackAdStatus::Pending,
            'enabled' => true,
        ]);

        return response()->json(['data' => $ad], 201);
    }
}
