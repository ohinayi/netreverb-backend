<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Extensions\RotateSipCredential;
use App\Http\Controllers\Controller;
use App\Models\Extension;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SipCredentialController extends Controller
{
    public function __construct(private RotateSipCredential $rotateSipCredential) {}

    public function __invoke(Organization $organization, Extension $extension): JsonResponse
    {
        Gate::authorize('rotateCredential', $extension);

        return response()->json([
            'data' => [
                'sip_password' => $this->rotateSipCredential->execute($extension),
                'display_once' => true,
            ],
        ]);
    }
}
