<?php

namespace Database\Factories;

use App\Enums\ConferenceRoomStatus;
use App\Models\ConferenceRoom;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConferenceRoom>
 */
class ConferenceRoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'host_user_id' => User::factory(),
            'room_id' => (string) Str::ulid(),
            'invite_code' => Str::random(22),
            'sip_number' => fake()->numerify('45####'),
            'title' => fake()->sentence(3),
            'status' => ConferenceRoomStatus::Active,
            'passcode_hash' => null,
            'expires_at' => now()->addMinutes(120),
            'ended_at' => null,
            'ended_by_user_id' => null,
            'configuration' => [
                'is_open' => false,
            ],
        ];
    }
}
