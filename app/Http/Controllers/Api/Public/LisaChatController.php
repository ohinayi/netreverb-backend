<?php

namespace App\Http\Controllers\Api\Public;

use App\Contracts\Ai\StructuredAssistantProvider;
use App\Http\Controllers\Controller;
use App\Models\WebsiteChatSession;
use App\Notifications\WebsiteLeadCaptured;
use App\Services\Ai\LisaChatInstructionBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Public, unauthenticated chat endpoint for "Lisa" - the marketing site's
 * own assistant, not a customer org's AiAssistant. Rate-limited by IP via
 * the route's throttle middleware, since this is reachable by anyone.
 */
class LisaChatController extends Controller
{
    // Past this many turns a real visitor is well served; stops an abusive
    // caller from running the Gemini bill up indefinitely on one session.
    private const MAX_MESSAGES = 60;

    public function send(Request $request, StructuredAssistantProvider $provider): JsonResponse
    {
        $data = $request->validate([
            'session_token' => ['sometimes', 'nullable', 'string'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $session = filled($data['session_token'] ?? null)
            ? WebsiteChatSession::query()->where('public_id', $data['session_token'])->first()
            : null;

        $session ??= WebsiteChatSession::query()->create(['ip' => $request->ip()]);

        $messages = $session->messages ?? [];

        if (count($messages) >= self::MAX_MESSAGES) {
            return response()->json([
                'session_token' => $session->public_id,
                'reply' => "We've covered a lot of ground here - for anything further, please reach out through the sign-up page and the team will follow up directly.",
            ]);
        }

        $messages[] = ['role' => 'visitor', 'text' => $data['message'], 'at' => now()->toIso8601String()];

        $transcript = collect($messages)
            ->map(fn (array $turn): string => ($turn['role'] === 'visitor' ? 'Visitor: ' : 'Lisa: ').$turn['text'])
            ->implode("\n");

        try {
            $result = $provider->extract(
                LisaChatInstructionBuilder::systemInstruction(),
                $transcript,
                LisaChatInstructionBuilder::responseSchema(),
            );
        } catch (RuntimeException $exception) {
            Log::error('Lisa website chat failed to get a Gemini reply.', ['exception' => $exception->getMessage()]);

            return response()->json([
                'session_token' => $session->public_id,
                'reply' => "Sorry, I'm having trouble replying right now - please try again in a moment.",
            ], 502);
        }

        $reply = is_string($result['reply'] ?? null) && $result['reply'] !== ''
            ? $result['reply']
            : 'Sorry, could you rephrase that?';

        $messages[] = ['role' => 'lisa', 'text' => $reply, 'at' => now()->toIso8601String()];

        $hadContact = filled($session->lead_contact);

        $session->fill([
            'messages' => $messages,
            'lead_name' => $result['lead_name'] ?? $session->lead_name,
            'lead_contact' => $result['lead_contact'] ?? $session->lead_contact,
            'lead_need' => $result['lead_need'] ?? $session->lead_need,
            'last_message_at' => now(),
        ])->save();

        if (! $hadContact && filled($session->lead_contact) && filled(config('superadmin.email'))) {
            $session->forceFill(['lead_notified_at' => now()])->save();
            Notification::route('mail', config('superadmin.email'))->notify(new WebsiteLeadCaptured($session));
        }

        return response()->json(['session_token' => $session->public_id, 'reply' => $reply]);
    }
}
