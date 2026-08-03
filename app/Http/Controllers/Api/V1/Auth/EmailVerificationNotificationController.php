<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            $validated = $request->validate([
                'email' => ['required', 'email:rfc', 'max:254'],
            ]);
            $user = User::query()->where('email', strtolower(trim($validated['email'])))->first();
        }

        if ($user && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => 'If verification is required, a new link has been sent.',
        ]);
    }
}
