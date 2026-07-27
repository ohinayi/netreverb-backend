<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditEventController extends Controller
{
    public function index(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('update', $organization);

        return response()->json([
            'data' => AuditEvent::query()
                ->whereBelongsTo($organization)
                ->with('actor:id,public_id,name,email')
                ->latest('id')
                ->paginate(50),
        ]);
    }
}
