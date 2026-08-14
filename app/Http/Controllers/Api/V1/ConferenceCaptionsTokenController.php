<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConferenceRoom;
use App\Support\CaptionsRelayToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ConferenceCaptionsTokenController extends Controller
{
    /**
     * Issues a short-lived token for the frontend to connect directly to the
     * captions relay's WebSocket - mirrors LiveKitTokenController's shape
     * and gate, but the token is a relay-verified HMAC pair
     * (App\Support\CaptionsRelayToken), not a LiveKit AccessToken, since the
     * browser is talking to the relay here, not to LiveKit.
     */
    public function __invoke(Request $request, ConferenceRoom|string $conferenceRoom): JsonResponse
    {
        if (is_string($conferenceRoom)) {
            $conferenceRoom = ConferenceRoom::query()
                ->where('public_id', $conferenceRoom)
                ->firstOrFail();
        }

        Gate::authorize('chat', $conferenceRoom);

        if (! config('captions.relay_secret')) {
            return response()->json([
                'message' => 'Live captions are not configured.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $user = $request->user();
        $ttl = max(60, min(300, (int) config('captions.token_ttl', 300)));

        $token = CaptionsRelayToken::issue([
            'conference_room_public_id' => $conferenceRoom->public_id,
            'identity' => 'user-'.$user->public_id,
        ], $ttl);

        return response()->json([
            'data' => [
                'url' => sprintf(
                    '%s/captions/netreverb-conference-%s',
                    rtrim((string) config('captions.relay_url'), '/'),
                    $conferenceRoom->public_id,
                ),
                'token' => $token,
                'expires_at' => now()->addSeconds($ttl)->toISOString(),
            ],
        ]);
    }
}
