<?php

namespace Database\Factories;

use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\ConferenceRoomReaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConferenceRoomReaction>
 */
class ConferenceRoomReactionFactory extends Factory
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
            'conference_room_participant_id' => ConferenceRoomParticipant::factory(),
            'user_id' => User::factory(),
            'reaction_type' => fake()->randomElement([
                'thumbs_up',
                'clap',
                'laugh',
                'heart',
                'celebrate',
                'wave',
            ]),
            'payload' => [
                'emoji' => '👍',
            ],
            'expires_at' => now()->addSeconds(20),
        ];
    }
}
