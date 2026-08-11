<?php

namespace App\Http\Controllers\Api\V1;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\VideoGrant;
use App\Http\Controllers\Controller;
use App\Models\ConferenceRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class LiveKitTokenController extends Controller
{
    public function __invoke(
        Request $request,
        ConferenceRoom|string $conferenceRoom,
    ): JsonResponse {
        // Some API route stacks pass the ULID as a raw string instead of
        // applying implicit binding. Resolve both forms explicitly so the
        // token endpoint is reliable for invite and organization routes.
        if (is_string($conferenceRoom)) {
            $conferenceRoom = ConferenceRoom::query()
                ->where('public_id', $conferenceRoom)
                ->firstOrFail();
        }

        // A LiveKit token is issued only after the user has joined/admitted
        // to this room. The chat policy covers both organization members and
        // invite guests with a joined participant record.
        Gate::authorize('chat', $conferenceRoom);

        if (! config('livekit.enabled') || ! config('livekit.api_key') || ! config('livekit.api_secret')) {
            return response()->json([
                'message' => 'LiveKit is not enabled.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $user = $request->user();
        $room = 'netreverb-conference-'.$conferenceRoom->public_id;
        $identity = 'user-'.$user->public_id;
        $metadata = json_encode([
            'user_id' => $user->public_id,
            'name' => $user->name,
            'conference_room_id' => $conferenceRoom->public_id,
        ], JSON_THROW_ON_ERROR);

        $options = (new AccessTokenOptions())
            ->setIdentity($identity)
            ->setName($user->name)
            ->setTtl(max(60, min(900, (int) config('livekit.token_ttl', 300))))
            ->setMetadata($metadata);

        $grant = (new VideoGrant())
            ->setRoomJoin()
            ->setRoomName($room)
            ->setCanPublish()
            ->setCanSubscribe()
            ->setCanPublishData();

        $token = (new AccessToken(config('livekit.api_key'), config('livekit.api_secret'), $options))
            ->setGrant($grant)
            ->toJwt();

        return response()->json([
            'data' => [
                'url' => config('livekit.url'),
                'token' => $token,
                'room' => $room,
                'identity' => $identity,
                'expires_at' => now()->addSeconds($options->getTtl())->toISOString(),
            ],
        ]);
    }
}
