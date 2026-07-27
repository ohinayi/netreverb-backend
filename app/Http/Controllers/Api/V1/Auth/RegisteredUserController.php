<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class RegisteredUserController extends Controller
{
    public function __construct(private RegisterUser $registerUser) {}

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = $this->registerUser->execute($request->validated());
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'data' => UserResource::make($user),
        ], Response::HTTP_CREATED);
    }
}
