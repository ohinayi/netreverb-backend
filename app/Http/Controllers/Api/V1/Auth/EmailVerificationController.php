<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Extensions\ProvisionVerifiedUserExtension;
use App\Actions\Organizations\SyncOrganizationMemberFriendships;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(
        private ProvisionVerifiedUserExtension $provisionExtension,
        private SyncOrganizationMemberFriendships $syncFriendships,
    ) {}

    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        $user = User::query()->findOrFail($request->route('id'));

        abort_unless(
            hash_equals((string) $request->route('hash'), sha1($user->getEmailForVerification())),
            403,
        );

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            // An invitation creates the account in an "invited" state. Email
            // verification is the acceptance step for a newly invited user, so
            // activate those memberships before the account can receive an
            // organization extension.
            $activatedMemberships = OrganizationMembership::query()
                ->whereBelongsTo($user)
                ->where('status', MembershipStatus::Invited->value)
                ->get();

            foreach ($activatedMemberships as $membership) {
                $membership->update([
                    'status' => MembershipStatus::Active->value,
                    'joined_at' => now(),
                ]);
                $this->syncFriendships->execute($membership->organization, $user);
            }

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
