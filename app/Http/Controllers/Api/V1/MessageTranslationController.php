<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Translation\MessageTranslationProvider;
use App\Enums\MessageType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TranslateMessageRequest;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Throwable;

class MessageTranslationController extends Controller
{
    public function __construct(private readonly MessageTranslationProvider $translator) {}

    public function store(
        TranslateMessageRequest $request,
        Conversation $conversation,
        Message $message,
    ): JsonResponse {
        Gate::authorize('view', $conversation);

        if ($message->conversation_id !== $conversation->id) {
            abort(404);
        }

        if ($message->type !== MessageType::Text || trim((string) $message->body) === '') {
            return response()->json([
                'message' => 'Only text messages can be translated.',
            ], 422);
        }

        $targetLocale = $request->filled('target_locale')
            ? $request->string('target_locale')->toString()
            : ($request->user()?->locale
                ?: config('translation.default_target_locale')
                ?: config('app.locale'));

        try {
            $result = $this->translator->translate(
                text: (string) $message->body,
                targetLocale: (string) $targetLocale,
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'We could not translate that message right now. Please try again.',
            ], 503);
        }

        return response()->json([
            'translated_text' => $result->translatedText,
            'source_language' => $result->sourceLanguage,
            'target_language' => $result->targetLanguage,
        ]);
    }
}
