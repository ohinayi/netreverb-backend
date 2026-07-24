<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function store(ForgotPasswordRequest $request): JsonResponse
    {
        // Always give the same response, so email addresses cannot be enumerated.
        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => 'If an account exists for that email, we sent a password reset link.']);
    }

    public function update(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset($request->validated(), function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            // The reset link proves control of the invited email address, so
            // it also completes verification for an admin-created account.
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            // Signing out other devices is important after an account recovery.
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['message' => 'Your password has been reset. You can sign in now.']);
    }
}
