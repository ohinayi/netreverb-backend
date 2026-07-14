<?php

namespace App\Actions\WebRtc;

use App\Enums\CallMediaType;
use App\Enums\CallSessionType;
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

        $supportsDirectVideoRecording = $this->supportsDirectVideoRecording();

        return [
            'wss' => config('telephony.secure_websocket_url'),
            'sip' => [
                'username' => $extension->dialableNumber->number,
                'password' => $extension->credential->password,
                'realm' => $extension->dialableNumber->realm,
                'expires' => config('telephony.sip_registration_expires'),
                'supports_video' => (bool) config('telephony.webrtc.video_enabled', true),
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
            'media' => [
                'audio' => [
                    'enabled' => true,
                ],
                'video' => [
                    'enabled' => (bool) config('telephony.webrtc.video_enabled', true),
                    'constraints' => [
                        'width' => [
                            'ideal' => (int) config('telephony.webrtc.video.width.ideal', 1280),
                            'max' => (int) config('telephony.webrtc.video.width.max', 1920),
                        ],
                        'height' => [
                            'ideal' => (int) config('telephony.webrtc.video.height.ideal', 720),
                            'max' => (int) config('telephony.webrtc.video.height.max', 1080),
                        ],
                        'frameRate' => [
                            'ideal' => (int) config('telephony.webrtc.video.frame_rate.ideal', 24),
                            'max' => (int) config('telephony.webrtc.video.frame_rate.max', 30),
                        ],
                        'facingMode' => (string) config('telephony.webrtc.video.facing_mode', 'user'),
                    ],
                    'max_bitrate_kbps' => (int) config('telephony.webrtc.video_max_bitrate_kbps', 1500),
                ],
            ],
            'calling' => [
                'default_media_type' => CallMediaType::Audio->value,
                'default_session_type' => CallSessionType::Direct->value,
                'supported_media_types' => array_map(
                    static fn (CallMediaType $mediaType): string => $mediaType->value,
                    CallMediaType::cases(),
                ),
                'supported_session_types' => array_map(
                    static fn (CallSessionType $sessionType): string => $sessionType->value,
                    CallSessionType::cases(),
                ),
                'screen_share_requires_conference' => true,
                'recording' => [
                    'direct_audio' => (bool) config('telephony.webrtc.recording.direct_audio_enabled', true),
                    'direct_video' => $supportsDirectVideoRecording,
                    'conference' => (bool) config('telephony.webrtc.recording.conference_audio_enabled', true),
                    'direct_audio_container' => (string) config('telephony.webrtc.recording.direct_audio_container', 'wav'),
                    'direct_video_container' => (string) config('telephony.webrtc.recording.direct_video_container', 'mp4'),
                    'conference_audio' => (bool) config('telephony.webrtc.recording.conference_audio_enabled', true),
                    'conference_video' => (bool) config('telephony.webrtc.recording.conference_video_enabled', false),
                    'conference_screen_share' => (bool) config('telephony.webrtc.recording.conference_screen_share_enabled', false),
                    'conference_audio_container' => (string) config('telephony.webrtc.recording.conference_audio_container', 'wav'),
                    'conference_video_container' => (string) config('telephony.webrtc.recording.conference_video_container', 'mp4'),
                ],
            ],
            'expires_at' => $expiresAt,
        ];
    }

    private function supportsDirectVideoRecording(): bool
    {
        if (! (bool) config('telephony.webrtc.recording.direct_video_enabled', false)) {
            return false;
        }

        $startTemplate = config('telephony.webrtc.recording.direct_video_start_command_template');
        $stopTemplate = config('telephony.webrtc.recording.direct_video_stop_command_template');

        return is_string($startTemplate)
            && trim($startTemplate) !== ''
            && is_string($stopTemplate)
            && trim($stopTemplate) !== '';
    }
}
