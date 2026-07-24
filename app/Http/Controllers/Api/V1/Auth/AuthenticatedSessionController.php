<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Organizations\SyncOrganizationMemberFriendships;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticatedSessionController extends Controller
{
    public function __construct(private SyncOrganizationMemberFriendships $syncFriendships) {}

    public function store(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email'))->first();

        if ($user === null || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials are incorrect.',
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => 'Verify your email address before signing in.',
            ]);
        }

        $user->update(['last_login_at' => now()]);

        // Reconcile legacy invitations created before membership acceptance was
        // moved to the organization administrator. A verified user should
        // never remain stranded in an invited state after signing in.
        if ($user->hasVerifiedEmail()) {
            $memberships = OrganizationMembership::query()
                ->whereBelongsTo($user)
                ->where('status', MembershipStatus::Invited->value)
                ->with('organization')
                ->get();

            foreach ($memberships as $membership) {
                $membership->update([
                    'status' => MembershipStatus::Active->value,
                    'joined_at' => $membership->joined_at ?? now(),
                ]);
                $this->syncFriendships->execute($membership->organization, $user);
            }
        }

        $token = $user->createToken($request->string('device_name', 'web')->toString());

        return response()->json([
            'data' => UserResource::make($user),
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
        ]);
    }

    public function destroy(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }
}
