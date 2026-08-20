<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SystemMonitoring\TelephonyInfrastructureHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuperAdminOperationsController extends Controller
{
    public function __construct(private readonly TelephonyInfrastructureHealth $telephonyHealth) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        return response()->json([
            'data' => $this->telephonyHealth->check(),
        ]);
    }
}
