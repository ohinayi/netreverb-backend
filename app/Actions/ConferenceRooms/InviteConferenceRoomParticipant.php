<?php

namespace App\Actions\ConferenceRooms;

use App\Enums\ConferenceParticipantStatus;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InviteConferenceRoomParticipant
{
    /**
     * @param array{
     *     user_public_id?: ?string,
     *     email?: ?string,
     *     display_name?: ?string,
     *     metadata?: ?array<string, mixed>,
     * } $attributes
     */
    public function execute(ConferenceRoom $conferenceRoom, array $attributes): ConferenceRoomParticipant
    {
        return DB::transaction(function () use ($conferenceRoom, $attributes): ConferenceRoomParticipant {
            $user = null;

            if (isset($attributes['user_public_id']) && $attributes['user_public_id'] !== null) {
                $user = User::query()
                    ->where('public_id', $attributes['user_public_id'])
                    ->firstOrFail();
            }

            $participant = ConferenceRoomParticipant::query()->updateOrCreate(
                [
                    'conference_room_id' => $conferenceRoom->id,
                    'user_id' => $user?->id,
                    'email' => $attributes['email'] ?? $user?->email,
                ],
                [
                    'display_name' => $attributes['display_name'] ?? $user?->name ?? $attributes['email'] ?? 'Guest participant',
                    'role' => 'participant',
                    'status' => ConferenceParticipantStatus::Invited,
                    'invited_at' => Carbon::now(),
                    'joined_at' => null,
                    'left_at' => null,
                    'metadata' => $attributes['metadata'] ?? null,
                ],
            );

            return $participant->load('user');
        }, attempts: 3);
    }
}
