<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\CompleteUserOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\CompleteOrganizationRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class CompleteOrganizationController extends Controller
{
    public function __construct(private CompleteUserOrganization $completeOrganization) {}

    public function __invoke(CompleteOrganizationRequest $request): JsonResponse
    {
        $user = $this->completeOrganization->execute($request->user(), $request->validated());

        if ($user === null) {
            return response()->json([
                'message' => 'Your account already has an organization set up.',
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'data' => UserResource::make($user->load([
                'extensions.dialableNumber',
                'extensions.organization',
                'extensions.provisioningState',
            ])),
        ]);
    }
}
