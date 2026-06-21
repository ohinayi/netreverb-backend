<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SipRegistrationResource;
use App\Models\Extension;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SipRegistrationController extends Controller
{
    public function __invoke(
        Organization $organization,
        Extension $extension,
    ): JsonResponse {
        Gate::authorize('viewSipRegistration', $extension);

        return SipRegistrationResource::make(
            $extension->loadMissing(['dialableNumber', 'credential', 'provisioningState']),
        )->response()->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
