<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\StoreDeviceTokenRequest;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registers/removes the FCM token a device needs to receive an incoming-call
 * push. A token is unique across the whole table (not per-user) because the
 * same physical device's token must move with whichever account is
 * currently logged in on it, rather than accumulating stale rows for
 * accounts that previously logged out on that device.
 */
class DeviceTokenController extends Controller
{
    public function store(StoreDeviceTokenRequest $request): JsonResponse
    {
        DeviceToken::query()->updateOrCreate(
            ['fcm_token' => $request->string('fcm_token')->toString()],
            [
                'user_id' => $request->user()->id,
                'platform' => $request->string('platform')->toString(),
                'device_name' => $request->string('device_name')->toString() ?: null,
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['message' => 'Device token registered.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $token = $request->string('fcm_token')->toString();

        if ($token !== '') {
            DeviceToken::query()
                ->where('user_id', $request->user()->id)
                ->where('fcm_token', $token)
                ->delete();
        }

        return response()->json(['message' => 'Device token removed.']);
    }
}
