<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\FinalizeUserLogin;
use App\Actions\Auth\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\MobileRegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class MobileRegisteredUserController extends Controller
{
    public function __construct(
        private RegisterUser $registerUser,
        private FinalizeUserLogin $finalizeLogin,
    ) {}

    /**
     * Same account-creation path as the web app's registration (same
     * validation, same RegisterUser action - a personal workspace +
     * extension gets queued the same way), just issuing a bearer token
     * instead of a session cookie. The new extension only actually
     * provisions once the emailed verification link is clicked - that
     * gate is shared with web, not mobile-specific.
     */
    public function store(MobileRegisterRequest $request): JsonResponse
    {
        $user = $this->registerUser->execute($request->validated());
        $this->finalizeLogin->execute($user);

        $token = $user->createToken($request->string('device_name')->toString())->plainTextToken;

        return response()->json([
            'data' => UserResource::make($user->load([
                'extensions.dialableNumber',
                'extensions.organization',
                'extensions.provisioningState',
            ])),
            'token' => $token,
        ], Response::HTTP_CREATED);
    }
}
