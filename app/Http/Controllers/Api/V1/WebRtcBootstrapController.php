<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\WebRtc\BuildWebRtcBootstrap;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\WebRtcBootstrapRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class WebRtcBootstrapController extends Controller
{
    public function __construct(private BuildWebRtcBootstrap $buildWebRtcBootstrap) {}

    public function __invoke(WebRtcBootstrapRequest $request): JsonResponse
    {
        $extension = $request->extension();
        Gate::authorize('viewSipRegistration', $extension);

        return response()->json(
            $this->buildWebRtcBootstrap->execute($extension, $request->user()),
        )->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
