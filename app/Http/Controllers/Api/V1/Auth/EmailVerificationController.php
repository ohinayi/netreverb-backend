<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Extensions\ProvisionVerifiedUserExtension;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private ProvisionVerifiedUserExtension $provisionExtension) {}

    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        $user = User::query()->findOrFail($request->route('id'));

        abort_unless(
            hash_equals((string) $request->route('hash'), sha1($user->getEmailForVerification())),
            403,
        );

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        $this->provisionExtension->execute($user);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Email verified successfully.']);
        }

        return redirect()->away(
            rtrim(config('app.frontend_url'), '/').'/auth/verify-email?status=success',
        );
    }
}
