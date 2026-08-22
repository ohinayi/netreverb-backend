<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DialableNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CallRingbackAudioController extends Controller
{
    /**
     * What a caller should hear while a destination number rings, resolved
     * by the CALLEE's organization - not the caller's own. Deliberately open
     * to any authenticated user regardless of org membership: you can dial
     * any extension on the platform, and this only ever returns an audio
     * URL, never anything about the destination itself.
     */
    public function effective(Request $request): JsonResponse
    {
        $number = trim((string) $request->query('number', ''));
        $organization = $number === ''
            ? null
            : DialableNumber::query()->where('number', $number)->first()?->organization;

        $path = $organization?->effectiveRingbackAudioPath();

        return response()->json(['data' => [
            'url' => $path ? '/storage/'.ltrim($path, '/') : null,
        ]]);
    }
}
