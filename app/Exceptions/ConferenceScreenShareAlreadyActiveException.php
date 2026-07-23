<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ConferenceScreenShareAlreadyActiveException extends RuntimeException implements ShouldntReport
{
    public static function alreadyActive(): self
    {
        return new self('Another participant is already sharing their screen.');
    }

    public function render(Request $request): JsonResponse|bool
    {
        if (! $request->is('api/*')) {
            return false;
        }

        return response()->json([
            'message' => 'Another participant is already sharing their screen.',
            'error_code' => 'screen_share_already_active',
        ], Response::HTTP_CONFLICT);
    }
}
