<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\AuthenticateWithCredentials;
use App\Actions\Auth\FinalizeUserLogin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\MobileLoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class MobileAuthenticatedSessionController extends Controller
{
    public function __construct(
        private AuthenticateWithCredentials $authenticate,
        private FinalizeUserLogin $finalizeLogin,
    ) {}

    public function store(MobileLoginRequest $request): JsonResponse
    {
        $user = $this->authenticate->execute($request->string('email')->toString(), $request->string('password')->toString());

        $this->finalizeLogin->execute($user);

        $token = $user->createToken($request->string('device_name')->toString())->plainTextToken;

        return response()->json([
            'data' => UserResource::make($user->load([
                'extensions.dialableNumber',
                'extensions.organization',
                'extensions.provisioningState',
            ])),
            'token' => $token,
        ]);
    }

    public function destroy(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }
}
