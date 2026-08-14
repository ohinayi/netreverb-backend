<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SystemMonitoring\PackageCatalog;
use App\Services\SystemMonitoring\PiperVoiceInstaller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class SuperAdminPackagesController extends Controller
{
    public function __construct(
        private readonly PackageCatalog $catalog,
        private readonly PiperVoiceInstaller $installer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        return response()->json([
            'data' => [
                'libretranslate' => $this->catalog->libreTranslate(),
                'piper_voices' => $this->catalog->piperVoices(),
            ],
        ]);
    }

    public function downloadVoice(Request $request, string $voice): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        try {
            $this->installer->install($voice);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->catalog->piperVoices(),
        ]);
    }
}
