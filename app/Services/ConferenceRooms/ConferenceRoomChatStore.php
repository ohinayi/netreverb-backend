<?php

namespace App\Services\ConferenceRooms;

use App\Models\ConferenceRoom;
use Illuminate\Support\Facades\Cache;

class ConferenceRoomChatStore
{
    private const MESSAGE_LIMIT = 50;

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
        $messages = Cache::get($this->messagesKey($conferenceRoom), []);

        return is_array($messages) ? array_values($messages) : [];
    }

    /**
     * @param  array{
     *     id: string,
     *     type: string,
     *     conference_room_public_id: string,
     *     participant_public_id: string,
     *     display_name: string,
     *     body: string,
     *     sent_at: string
     * }  $message
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
    public function append(ConferenceRoom $conferenceRoom, array $message): array
    {
        $messages = $this->history($conferenceRoom);
        $messages[] = $message;

        $messages = array_slice($messages, -1 * $this->messageLimit());

        Cache::put(
            $this->messagesKey($conferenceRoom),
            $messages,
            now()->addSeconds($this->cacheTtlSeconds($conferenceRoom)),
        );

        return $message;
    }

    public function forget(ConferenceRoom $conferenceRoom): void
    {
        Cache::forget($this->messagesKey($conferenceRoom));
        Cache::forget($this->versionKey($conferenceRoom));
    }

    public function bumpVersion(ConferenceRoom $conferenceRoom): int
    {
        $key = $this->versionKey($conferenceRoom);
        $version = (int) Cache::get($key, 0) + 1;

        Cache::put($key, $version, now()->addSeconds($this->cacheTtlSeconds($conferenceRoom)));

        return $version;
    }

    public function version(ConferenceRoom $conferenceRoom): int
    {
        return (int) Cache::get($this->versionKey($conferenceRoom), 0);
    }

    private function messagesKey(ConferenceRoom $conferenceRoom): string
    {
        return 'conference_rooms:'.$conferenceRoom->public_id.':chat:messages';
    }

    private function versionKey(ConferenceRoom $conferenceRoom): string
    {
        return 'conference_rooms:'.$conferenceRoom->public_id.':chat:version';
    }

    private function cacheTtlSeconds(ConferenceRoom $conferenceRoom): int
    {
        $expiresAt = $conferenceRoom->expires_at;

        if ($expiresAt !== null) {
            return max(60, now()->diffInSeconds($expiresAt, false) > 0
                ? now()->diffInSeconds($expiresAt)
                : 60);
        }

        return 86400;
    }

    private function messageLimit(): int
    {
        return max(1, (int) config('conference.chat.history_limit', self::MESSAGE_LIMIT));
    }
}
