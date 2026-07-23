<?php

namespace App\Services\ConferenceRooms;

use App\Enums\ConferenceParticipantStatus;
use App\Exceptions\ConferenceRoomChatAccessDeniedException;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConferenceRoomChatService
{
    public function __construct(private ConferenceRoomChatStore $store) {}

    /**
     * @return array{
     *     messages_url: string,
     *     stream_url: string,
     *     websocket_url: string,
     *     channel_name: string,
     *     history: array<int, array{
     *         id: string,
     *         type: string,
     *         conference_room_public_id: string,
     *         participant_public_id: string,
     *         display_name: string,
     *         body: string,
     *         sent_at: string
     *     }>
     * }
     */
    public function bootstrap(ConferenceRoom $conferenceRoom): array
    {
        return [
            'messages_url' => route('conference-rooms.chat.messages.store', $conferenceRoom),
            'stream_url' => route('conference-rooms.chat.stream', $conferenceRoom),
            'websocket_url' => str_replace('{conferenceRoom}', $conferenceRoom->public_id, (string) config('conference.chat.websocket_url')),
            'channel_name' => 'private-conference.chat.'.$conferenceRoom->public_id,
            'history' => $this->store->history($conferenceRoom),
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     type: string,
     *     conference_room_public_id: string,
     *     participant_public_id: string,
     *     display_name: string,
     *     body: string,
     *     sent_at: string
     * }
     */
    public function sendMessage(ConferenceRoom $conferenceRoom, User $user, string $body): array
    {
        $participant = $this->resolveActiveParticipant($conferenceRoom, $user);
        $rateLimitKey = 'conference-chat:'.$conferenceRoom->public_id.':'.$participant->public_id;
        $maxMessages = max(1, (int) config('conference.chat.rate_limit_max_messages', 10));
        $decaySeconds = max(1, (int) config('conference.chat.rate_limit_decay_seconds', 10));

        $message = RateLimiter::attempt(
            $rateLimitKey,
            $maxMessages,
            function () use ($conferenceRoom, $participant, $body): array {
                $message = [
                    'id' => (string) Str::uuid(),
                    'type' => 'conference.chat.message',
                    'conference_room_public_id' => $conferenceRoom->public_id,
                    'participant_public_id' => $participant->public_id,
                    'display_name' => $participant->display_name,
                    'body' => $body,
                    'sent_at' => now()->utc()->toIso8601ZuluString(),
                ];

                $this->store->append($conferenceRoom, $message);
                $this->store->bumpVersion($conferenceRoom);

                return $message;
            },
            $decaySeconds,
        );

        if (! is_array($message)) {
            throw ValidationException::withMessages([
                'body' => 'You are sending messages too quickly. Please slow down.',
            ]);
        }

        return $message;
    }

    public function isActiveParticipant(ConferenceRoom $conferenceRoom, User $user): bool
    {
        return $this->resolveParticipant($conferenceRoom, $user)?->status === ConferenceParticipantStatus::Joined;
    }

    public function clearRoom(ConferenceRoom $conferenceRoom): void
    {
        $this->store->forget($conferenceRoom);
    }

    /**
     * @return array<int, array{
     *     id: string,
     *     type: string,
     *     conference_room_public_id: string,
     *     participant_public_id: string,
     *     display_name: string,
     *     body: string,
     *     sent_at: string
     * }>
     */
    public function history(ConferenceRoom $conferenceRoom): array
    {
        return $this->store->history($conferenceRoom);
    }

    public function messageCount(ConferenceRoom $conferenceRoom): int
    {
        return count($this->store->history($conferenceRoom));
    }

    public function roomVersion(ConferenceRoom $conferenceRoom): int
    {
        return $this->store->version($conferenceRoom);
    }

    private function resolveActiveParticipant(ConferenceRoom $conferenceRoom, User $user): ConferenceRoomParticipant
    {
        $participant = $this->resolveParticipant($conferenceRoom, $user);

        if ($participant === null || $participant->status !== ConferenceParticipantStatus::Joined) {
            throw new ConferenceRoomChatAccessDeniedException;
        }

        return $participant;
    }

    private function resolveParticipant(ConferenceRoom $conferenceRoom, User $user): ?ConferenceRoomParticipant
    {
        return ConferenceRoomParticipant::query()
            ->with('user')
            ->where('conference_room_id', $conferenceRoom->id)
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
    }
}
