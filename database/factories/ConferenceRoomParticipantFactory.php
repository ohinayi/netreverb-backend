<?php

namespace Database\Factories;

use App\Enums\ConferenceParticipantStatus;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConferenceRoomParticipant>
 */
class ConferenceRoomParticipantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conference_room_id' => ConferenceRoom::factory(),
            'user_id' => User::factory(),
            'display_name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'role' => 'participant',
            'status' => ConferenceParticipantStatus::Invited,
            'invited_at' => now(),
            'joined_at' => null,
            'left_at' => null,
            'metadata' => null,
        ];
    }
}
