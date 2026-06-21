<?php

namespace App\Actions\WebRtc;

use App\Models\Extension;
use App\Models\User;
use RuntimeException;

class BuildWebRtcBootstrap
{
    /** @return array<string, mixed> */
    public function execute(Extension $extension, User $user): array
    {
        $turnSecret = config('telephony.turn.secret');

        if (! is_string($turnSecret) || $turnSecret === '') {
            throw new RuntimeException('TURN shared-secret authentication is not configured.');
        }

        $expiresAt = now()->addSeconds(config('telephony.turn.ttl'))->timestamp;
        $turnUsername = $expiresAt.':'.$user->public_id;
        $turnCredential = base64_encode(hash_hmac('sha1', $turnUsername, $turnSecret, true));

        return [
            'wss' => config('telephony.secure_websocket_url'),
            'sip' => [
                'username' => $extension->dialableNumber->number,
                'password' => $extension->credential->password,
                'realm' => $extension->dialableNumber->realm,
                'expires' => config('telephony.sip_registration_expires'),
            ],
            'iceServers' => [
                [
                    'urls' => [
                        'stun:'.config('telephony.turn.host').':'.config('telephony.turn.port'),
                        config('telephony.turn.fallback_stun_url'),
                    ],
                ],
                [
                    'urls' => [
                        'turn:'.config('telephony.turn.host').':'.config('telephony.turn.port').'?transport=udp',
                        'turns:'.config('telephony.turn.host').':'.config('telephony.turn.tls_port').'?transport=tcp',
                    ],
                    'username' => $turnUsername,
                    'credential' => $turnCredential,
                ],
            ],
            'expires_at' => $expiresAt,
        ];
    }
}
