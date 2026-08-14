<?php

namespace App\Support;

/**
 * Short-lived HMAC-signed token authenticating the frontend and LiveKit's
 * egress workers to the standalone Node captions relay (never Laravel — the
 * relay verifies this itself with the same shared secret, no round trip
 * back to this app). Deliberately not a JWT library: this is a single
 * base64url(payload).base64url(hmac) pair, trivial to verify with Node's
 * built-in `crypto` module without adding a dependency on either side.
 */
class CaptionsRelayToken
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function issue(array $payload, int $ttlSeconds): string
    {
        $payload['exp'] = now()->addSeconds($ttlSeconds)->timestamp;

        $encodedPayload = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = self::base64UrlEncode(
            hash_hmac('sha256', $encodedPayload, (string) config('captions.relay_secret'), true),
        );

        return $encodedPayload.'.'.$signature;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
