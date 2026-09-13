<?php

namespace App\Services\Push;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sends a push through the FCM HTTP v1 API using a Firebase service-account
 * key rather than the older legacy server-key API (Google deprecated that
 * one). Auths itself via a self-signed JWT exchanged for a short-lived
 * OAuth2 access token — no extra SDK dependency, `firebase/php-jwt` was
 * already installed for something else.
 */
class FcmPushSender
{
    public function send(string $deviceToken, array $data): bool
    {
        try {
            $accessToken = $this->accessToken();
        } catch (Throwable $exception) {
            Log::error('Could not obtain an FCM access token.', ['error' => $exception->getMessage()]);

            return false;
        }

        $projectId = (string) config('services.fcm.project_id');

        $response = Http::withToken($accessToken)
            ->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $deviceToken,
                    // Data-only (no `notification` key): the app itself
                    // decides how to render the incoming-call screen and
                    // ringtone, rather than the OS drawing a generic push.
                    'data' => array_map(strval(...), $data),
                    'android' => [
                        'priority' => 'high',
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::warning('FCM push send failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm.access_token', 3000, function (): string {
            $credentialsPath = (string) config('services.fcm.credentials_path');

            if ($credentialsPath === '' || ! is_readable($credentialsPath)) {
                throw new RuntimeException('FCM credentials file is not configured or not readable.');
            }

            $credentials = json_decode((string) file_get_contents($credentialsPath), true);

            if (! is_array($credentials) || ! isset($credentials['client_email'], $credentials['private_key'])) {
                throw new RuntimeException('FCM credentials file is missing client_email/private_key.');
            }

            $now = time();
            $assertion = JWT::encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], $credentials['private_key'], 'RS256');

            $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

            if ($response->failed() || ! is_string($response->json('access_token'))) {
                throw new RuntimeException('Google token endpoint did not return an access token: '.$response->body());
            }

            return $response->json('access_token');
        });
    }
}
