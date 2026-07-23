<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ConferenceRooms\TouchConferenceRoomExpiry;
use App\Exceptions\ConferenceRoomChatAccessDeniedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreConferenceRoomChatMessageRequest;
use App\Models\ConferenceRoom;
use App\Services\ConferenceRooms\ConferenceRoomChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConferenceRoomChatController extends Controller
{
    public function __construct(
        private ConferenceRoomChatService $chatService,
        private TouchConferenceRoomExpiry $touchConferenceRoomExpiry,
    ) {}

    public function show(Request $request, ConferenceRoom $conferenceRoom): JsonResponse
    {
        $conferenceRoom = $this->ensureRoomChatAccessible($conferenceRoom, $request);

        return response()->json([
            'type' => 'conference.chat.bootstrap',
            'data' => $this->chatService->bootstrap($conferenceRoom),
        ]);
    }

    public function store(StoreConferenceRoomChatMessageRequest $request, ConferenceRoom $conferenceRoom): JsonResponse
    {
        $conferenceRoom = $this->ensureRoomChatAccessible($conferenceRoom, $request);

        $message = $this->chatService->sendMessage(
            $conferenceRoom,
            $request->user(),
            $request->string('body')->toString(),
        );

        return response()->json([
            'type' => 'conference.chat.message',
            'data' => $message,
        ], Response::HTTP_CREATED);
    }

    public function stream(Request $request, ConferenceRoom $conferenceRoom): StreamedResponse
    {
        $conferenceRoom = $this->ensureRoomChatAccessible($conferenceRoom, $request);
        $chatService = $this->chatService;

        return response()->stream(function () use ($conferenceRoom, $chatService): void {
            ignore_user_abort(true);
            set_time_limit(0);

            $lastCount = 0;
            $lastHeartbeat = microtime(true);
            $messages = $chatService->history($conferenceRoom);

            $this->sendSse('conference.chat.snapshot', [
                'conference_room_public_id' => $conferenceRoom->public_id,
                'messages' => $messages,
            ]);

            $lastCount = count($messages);

            while (! connection_aborted()) {
                $messages = $chatService->history($conferenceRoom);
                $messageCount = count($messages);

                if ($messageCount > $lastCount) {
                    foreach (array_slice($messages, $lastCount) as $message) {
                        $this->sendSse('conference.chat.message', $message);
                    }

                    $lastCount = $messageCount;
                }

                if ((microtime(true) - $lastHeartbeat) >= 15) {
                    echo ": heartbeat\n\n";
                    @ob_flush();
                    flush();
                    $lastHeartbeat = microtime(true);
                }

                usleep(500000);
            }
        }, 200, [
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function ensureRoomChatAccessible(ConferenceRoom $conferenceRoom, Request $request): ConferenceRoom
    {
        $conferenceRoom = $this->touchConferenceRoomExpiry->execute($conferenceRoom);
        $conferenceRoom->refresh();

        if ($conferenceRoom->status->value !== 'active') {
            throw new ConferenceRoomChatAccessDeniedException;
        }

        if (! $this->chatService->isActiveParticipant($conferenceRoom, $request->user())) {
            throw new ConferenceRoomChatAccessDeniedException;
        }

        return $conferenceRoom;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sendSse(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_SLASHES)."\n\n";
        @ob_flush();
        flush();
    }
}
