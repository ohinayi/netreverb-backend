<?php

use App\Enums\ConferenceParticipantStatus;
use App\Enums\MembershipStatus;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/** @param  User  $user */
$authorizeConferenceRoomParticipant = function (User $user, ConferenceRoom $conferenceRoom): bool {
    if ($conferenceRoom->host_user_id === $user->id) {
        return true;
    }

    $participantExists = ConferenceRoomParticipant::query()
        ->where('conference_room_id', $conferenceRoom->id)
        ->where('user_id', $user->id)
        ->primary()
        ->whereIn('status', [
            ConferenceParticipantStatus::Invited->value,
            ConferenceParticipantStatus::Waiting->value,
            ConferenceParticipantStatus::Joined->value,
        ])
        ->exists();

    if ($participantExists) {
        return true;
    }

    return $user->organizationMemberships()
        ->where('organization_id', $conferenceRoom->organization_id)
        ->where('status', MembershipStatus::Active->value)
        ->exists();
};

Broadcast::channel('conference.rooms.{conferenceRoom}', $authorizeConferenceRoomParticipant);
Broadcast::channel('conference.chat.{conferenceRoom}', $authorizeConferenceRoomParticipant);
