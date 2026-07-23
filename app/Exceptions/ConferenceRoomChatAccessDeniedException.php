<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConferenceRoomChatAccessDeniedException extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'You are not an active participant in this conference room.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'type' => 'error',
            'error_code' => 'conference_chat_access_denied',
            'message' => 'You are not an active participant in this conference room.',
        ], 403);
    }
}
