<?php

namespace App\Http\Middleware;

use App\Models\OrganizationApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a request to the public partner API (`v1/partner/*`) by
 * bearer API key rather than Sanctum - a partner integration has no
 * NetReverb `User` to authenticate as, it authenticates as the
 * organization itself. Plain middleware, not an auth guard: forcing this
 * through `auth:*` would imply a `User` this flow never has.
 */
class AuthenticatePartnerApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || ! str_starts_with($token, 'nrb_')) {
            abort(401, 'Missing or invalid API key.');
        }

        $key = OrganizationApiKey::query()->where('key_hash', hash('sha256', $token))->first();

        if ($key === null || ! $key->isActive()) {
            abort(401, 'Missing or invalid API key.');
        }

        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('partner_api_key', $key);
        $request->attributes->set('partner_organization', $key->organization);

        return $next($request);
    }
}
