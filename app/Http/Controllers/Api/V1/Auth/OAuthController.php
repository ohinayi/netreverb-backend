<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Extensions\ProvisionVerifiedUserExtension;
use App\Actions\Organizations\SyncOrganizationMemberFriendships;
use App\Enums\AccountType;
use App\Enums\MembershipStatus;
use App\Exceptions\OAuthLoginException;
use App\Http\Controllers\Controller;
use App\Models\OrganizationMembership;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class OAuthController extends Controller
{
    public function __construct(
        private ProvisionVerifiedUserExtension $provisionExtension,
        private SyncOrganizationMemberFriendships $syncFriendships,
    ) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $provider = $this->normalizeProvider($provider);

        if (! $this->providerIsEnabled($provider)) {
            return $this->redirectToFrontendLogin('provider_disabled', $provider);
        }

        $request->session()->put('oauth.redirect', $this->normalizeFrontendRedirect($request->query('redirect')));

        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $provider = $this->normalizeProvider($provider);

        if (! $this->providerIsEnabled($provider)) {
            return $this->redirectToFrontendLogin('provider_disabled', $provider);
        }

        try {
            $socialiteUser = Socialite::driver($provider)->user();
        } catch (InvalidStateException) {
            return $this->redirectToFrontendLogin('invalid_state', $provider);
        } catch (Throwable) {
            return $this->redirectToFrontendLogin('provider_error', $provider);
        }

        try {
            $user = DB::transaction(function () use ($provider, $socialiteUser): User {
                return $this->resolveUserForProvider($provider, $socialiteUser);
            });
        } catch (OAuthLoginException $exception) {
            return $this->redirectToFrontendLogin($exception->errorCode, $provider);
        }

        $this->completeLogin($request, $user);

        $redirectPath = $this->normalizeFrontendRedirect(
            (string) $request->session()->pull('oauth.redirect', '/app/home'),
        );

        return redirect()->away($this->frontendCallbackUrl([
            'provider' => $provider,
            'status' => 'success',
            'redirect' => $redirectPath,
        ]));
    }

    private function resolveUserForProvider(string $provider, SocialiteUser $socialiteUser): User
    {
        $providerUserId = (string) $socialiteUser->getId();
        $providerEmail = $this->normalizeEmail($socialiteUser->getEmail());
        $emailVerified = $this->providerEmailIsVerified($socialiteUser);

        if ($providerEmail === null || ! $emailVerified) {
            throw OAuthLoginException::forCode('provider_email_unavailable');
        }

        $socialAccount = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->with('user')
            ->first();

        if ($socialAccount) {
            $this->refreshSocialAccount($socialAccount, $socialiteUser);
            $this->syncUserNameFromProvider($socialAccount->user, $socialiteUser);
            $this->markUserVerifiedIfSafe($socialAccount->user, $providerEmail, $emailVerified);

            return $socialAccount->user;
        }

        $user = User::query()
            ->whereRaw('lower(email) = ?', [$providerEmail])
            ->first();

        if ($user) {
            $this->syncUserNameFromProvider($user, $socialiteUser);
            $this->markUserVerifiedIfSafe($user, $providerEmail, $emailVerified);
            $this->linkSocialAccount($user, $provider, $providerUserId, $socialiteUser);

            return $user;
        }

        $user = User::query()->create([
            'name' => $this->resolveDisplayName($socialiteUser, $providerEmail),
            'email' => $providerEmail,
            'password' => null,
            'account_type' => AccountType::from(config('oauth.default_account_type', AccountType::Individual->value)),
            'country_code' => $this->resolveCountryCode($socialiteUser),
            'timezone' => $this->resolveTimezone($socialiteUser),
            'locale' => $this->resolveLocale($socialiteUser),
            'email_verified_at' => now(),
        ]);

        $this->linkSocialAccount($user, $provider, $providerUserId, $socialiteUser);

        return $user;
    }

    private function completeLogin(Request $request, User $user): void
    {
        $user->update(['last_login_at' => now()]);

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

        $this->provisionExtension->execute($user);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
    }

    private function refreshSocialAccount(SocialAccount $socialAccount, SocialiteUser $socialiteUser): void
    {
        $socialAccount->fill([
            'avatar_url' => $socialiteUser->getAvatar() ?: $socialAccount->avatar_url,
            'access_token' => $this->normalizedToken($socialiteUser->token, $socialAccount->access_token),
            'refresh_token' => $this->normalizedToken($socialiteUser->refreshToken, $socialAccount->refresh_token),
            'expires_at' => $socialiteUser->expiresIn ? now()->addSeconds((int) $socialiteUser->expiresIn) : $socialAccount->expires_at,
        ])->save();
    }

    private function linkSocialAccount(User $user, string $provider, string $providerUserId, SocialiteUser $socialiteUser): void
    {
        SocialAccount::query()->updateOrCreate([
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
        ], [
            'user_id' => $user->getKey(),
            'avatar_url' => $socialiteUser->getAvatar(),
            'access_token' => $this->normalizedToken($socialiteUser->token),
            'refresh_token' => $this->normalizedToken($socialiteUser->refreshToken),
            'expires_at' => $socialiteUser->expiresIn ? now()->addSeconds((int) $socialiteUser->expiresIn) : null,
        ]);
    }

    private function markUserVerifiedIfSafe(User $user, string $email, bool $providerVerified): void
    {
        if (! $providerVerified || ! hash_equals(Str::lower(trim($user->email)), $email)) {
            return;
        }

        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        event(new Verified($user));
    }

    private function providerEmailIsVerified(SocialiteUser $socialiteUser): bool
    {
        $profile = $socialiteUser->user ?? [];

        return (bool) (
            data_get($profile, 'email_verified')
            ?? data_get($profile, 'verified_email')
            ?? data_get($profile, 'is_verified')
        );
    }

    private function resolveDisplayName(SocialiteUser $socialiteUser, string $email): string
    {
        $name = trim((string) $socialiteUser->getName());
        if ($name !== '') {
            return Str::limit($name, 120, '');
        }

        $nickname = trim((string) $socialiteUser->getNickname());
        if ($nickname !== '') {
            return Str::limit($nickname, 120, '');
        }

        return Str::before($email, '@');
    }

    private function syncUserNameFromProvider(User $user, SocialiteUser $socialiteUser): void
    {
        $providerName = $this->resolveProviderDisplayName($socialiteUser);

        if ($providerName === null || $providerName === $user->name) {
            return;
        }

        $user->forceFill(['name' => $providerName])->save();
    }

    private function resolveProviderDisplayName(SocialiteUser $socialiteUser): ?string
    {
        $name = trim((string) $socialiteUser->getName());
        if ($name !== '') {
            return Str::limit($name, 120, '');
        }

        $nickname = trim((string) $socialiteUser->getNickname());
        if ($nickname !== '') {
            return Str::limit($nickname, 120, '');
        }

        return null;
    }

    private function resolveLocale(SocialiteUser $socialiteUser): string
    {
        $locale = trim((string) data_get($socialiteUser->user, 'locale', ''));

        return $locale !== '' ? Str::limit($locale, 10, '') : config('app.locale');
    }

    private function resolveTimezone(SocialiteUser $socialiteUser): string
    {
        $timezone = trim((string) data_get($socialiteUser->user, 'timezone', ''));

        return $timezone !== '' ? Str::limit($timezone, 64, '') : 'UTC';
    }

    private function resolveCountryCode(SocialiteUser $socialiteUser): ?string
    {
        $locale = trim((string) data_get($socialiteUser->user, 'locale', ''));

        if (! preg_match('/^[A-Za-z]{2}(?:[-_](?<region>[A-Za-z]{2}))?$/', $locale, $matches)) {
            return null;
        }

        return isset($matches['region']) ? Str::upper($matches['region']) : null;
    }

    private function normalizedToken(?string $token, ?string $fallback = null): ?string
    {
        $token = trim((string) $token);

        return $token !== '' ? $token : $fallback;
    }

    private function normalizeProvider(string $provider): string
    {
        return Str::lower(trim($provider));
    }

    private function providerIsEnabled(string $provider): bool
    {
        return in_array($provider, array_map(
            static fn (mixed $value): string => Str::lower((string) $value),
            config('oauth.enabled_providers', []),
        ), true);
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email === '' ? null : Str::lower($email);
    }

    private function normalizeFrontendRedirect(?string $redirect): string
    {
        $redirect = trim((string) $redirect);

        if ($redirect === '' || str_starts_with($redirect, '//') || preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $redirect)) {
            return '/app/home';
        }

        return str_starts_with($redirect, '/') ? $redirect : '/app/home';
    }

    private function frontendCallbackUrl(array $query): string
    {
        return rtrim(config('app.frontend_url'), '/').'/auth/oauth/callback?'.http_build_query(
            $query,
            encoding_type: PHP_QUERY_RFC3986,
        );
    }

    private function frontendLoginUrl(array $query): string
    {
        return rtrim(config('app.frontend_url'), '/').'/auth/login?'.http_build_query(
            $query,
            encoding_type: PHP_QUERY_RFC3986,
        );
    }

    private function redirectToFrontendLogin(string $errorCode, string $provider): RedirectResponse
    {
        return redirect()->away($this->frontendLoginUrl([
            'oauth_error' => $errorCode,
            'provider' => $provider,
        ]));
    }
}
