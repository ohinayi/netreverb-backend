<?php

namespace App\Actions\ConferenceRooms;

use App\Models\ConferenceRoom;
use Illuminate\Support\Str;
use RuntimeException;

class GenerateConferenceRoomInviteCode
{
    public function execute(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $inviteCode = Str::random(22);

            if (! ConferenceRoom::query()->where('invite_code', $inviteCode)->exists()) {
                return $inviteCode;
            }
        }

        throw new RuntimeException('Unable to generate a unique conference room invite code.');
    }
}
